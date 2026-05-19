<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects every statically recognisable dispatch site in a parsed file.
 *
 * The visitor is stateful: it maintains a class+method stack and a closure
 * depth counter so each recorded site carries its enclosing
 * `(classFqcn, methodName)` context. The cross-link pass joins these against
 * listeners[], observers[] and events[] to populate downstream relations.
 *
 * Unresolvable dispatch arguments produce structured unresolved entries
 * conforming to `$defs/unresolvedDispatch` (without the `file` field, which
 * the scanner fills in after traversal).
 *
 * See docs/scanners/dispatches.md for the full design.
 */
final class DispatchSiteVisitor extends NodeVisitorAbstract
{
    /**
     * Methods that, when called statically on the Mail facade, take the
     * mailable as their first argument.
     */
    private const MAIL_OUTERMOST_METHODS_ARG0 = ['send', 'queue'];

    /**
     * Methods that, when called statically on the Mail facade, take the
     * mailable as their second argument (delay is index 0).
     */
    private const MAIL_OUTERMOST_METHODS_ARG1 = ['later'];

    /**
     * Mail facade root methods that initiate a chain (e.g. `Mail::to(...)`).
     */
    private const MAIL_CHAIN_ROOT_METHODS = ['to', 'cc', 'bcc', 'locale', 'mailer'];

    /**
     * Notification facade methods that take recipients as arg 0 and the
     * notification as arg 1.
     */
    private const NOTIFICATION_FACADE_METHODS = ['send', 'sendNow'];

    /**
     * Notification methods (instance form) that take the notification as
     * their first argument.
     */
    private const NOTIFY_METHODS = ['notify', 'notifyNow'];

    /** @var array<int, array{class: ?string, method: ?string}> */
    private array $classStack = [];

    private int $closureDepth = 0;

    /**
     * @var array<int, array{
     *     classFqcn: ?string,
     *     method: ?string,
     *     target: string,
     *     form: 'helper'|'facade'|'job_helper'|'dispatchable'|'mail_facade'|'mail_chain'|'notify_method'|'notification_facade'|'notification_chain',
     *     provisionalKind: 'event'|'job'|'ambiguous'|'mailable'|'notification',
     *     file: ?string,
     *     line: int,
     *     confidence: 'high'
     * }>
     */
    private array $sites = [];

    /** @var array<int, array{file: ?string, line: int, expression: string, reason: string}> */
    private array $unresolved = [];

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classStack = [];
        $this->closureDepth = 0;
        $this->sites = [];
        $this->unresolved = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        // Treat Trait_ and Class_ identically (trait gap is documented).
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $fqcn = $node->namespacedName?->toString();
            $this->classStack[] = ['class' => $fqcn, 'method' => null];

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->classStack !== []) {
                $top = count($this->classStack) - 1;
                $this->classStack[$top]['method'] = $node->name->toString();
            }

            return null;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Read dispatches in leaveNode so NameResolver has resolved every child
        // Name in the argument expressions before we inspect them.
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        } elseif ($node instanceof Node\Expr\MethodCall) {
            $this->handleMethodCall($node);
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            array_pop($this->classStack);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->classStack !== []) {
                $top = count($this->classStack) - 1;
                $this->classStack[$top]['method'] = null;
            }

            return null;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth = max(0, $this->closureDepth - 1);
        }

        return null;
    }

    private function handleFuncCall(Node\Expr\FuncCall $node): void
    {
        if (! $node->name instanceof Node\Name) {
            return;
        }

        $name = strtolower($node->name->toString());

        if ($name === 'event') {
            $this->recordHelperOrFacade($node, $node->args, 'helper', 'event', 'event');

            return;
        }

        if ($name === 'dispatch') {
            $this->recordHelperOrFacade($node, $node->args, 'job_helper', 'job', 'dispatch');
        }

        // dispatch_sync / dispatch_now intentionally skipped.
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();
        $className = $node->class->toString();

        // Mail facade — static-only forms: Mail::send(...), Mail::queue(...),
        // Mail::later($delay, ...). Chain-rooted forms (`Mail::to(...)->send`)
        // are handled in handleMethodCall.
        if (Facades::matches($className, Facades::MAIL)) {
            if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG0, true)) {
                $this->recordMailableSiteFromArg($node, $node->args, 0, 'mail_facade', 'Mail::'.$methodName);

                return;
            }
            if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true)) {
                $this->recordMailableSiteFromArg($node, $node->args, 1, 'mail_facade', 'Mail::'.$methodName);

                return;
            }

            // Other Mail facade methods (to/cc/etc.) are chain roots — they
            // don't carry a mailable target themselves.
            return;
        }

        // Notification facade — Notification::send($recipients, new X),
        // Notification::sendNow($recipients, new X).
        if (Facades::matches($className, Facades::NOTIFICATION)) {
            if (in_array($methodName, self::NOTIFICATION_FACADE_METHODS, true)) {
                $this->recordNotificationSiteFromArg($node, $node->args, 1, 'notification_facade', 'Notification::'.$methodName);

                return;
            }

            // Notification::route(...) is a chain root — no target by itself.
            return;
        }

        if ($methodName !== 'dispatch') {
            // dispatchSync / dispatchNow intentionally skipped.
            return;
        }

        if (Facades::matches($className, Facades::EVENT)) {
            $this->recordHelperOrFacade($node, $node->args, 'facade', 'event', 'Event::dispatch');

            return;
        }

        if (Facades::matches($className, Facades::BUS)) {
            $this->recordHelperOrFacade($node, $node->args, 'job_helper', 'job', 'Bus::dispatch');

            return;
        }

        // Dispatchable form: X::dispatch(...). Target is the class itself.
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = [
            'classFqcn' => $this->currentClassFqcn(),
            'method' => $this->currentMethod(),
            'target' => $className,
            'form' => 'dispatchable',
            'provisionalKind' => 'ambiguous',
            'file' => null,
            'line' => $node->getStartLine(),
            'confidence' => 'high',
        ];
    }

    /**
     * Handle MethodCall nodes for:
     *  - $any->notify(new X) / $any->notifyNow(new X)
     *  - Mail::to(...)->send(new X) / ->queue(new X) / ->later($delay, new X)
     *    (and chained through cc/bcc/locale/mailer)
     *  - Notification::route(...)->notify(new X)
     *
     * We act only when the current node is the outermost call of the
     * relevant shape — i.e. the chain-root walk leads to a recognised
     * Mail / Notification facade static call. For instance `notify` we
     * trust the method name and act regardless of receiver shape.
     */
    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        $methodName = $node->name->toString();

        // Mail chain: ->send / ->queue / ->later whose receiver chain roots
        // at a Mail facade static call (Mail::to / Mail::cc / Mail::bcc /
        // Mail::locale / Mail::mailer).
        if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG0, true)
            || in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true)
        ) {
            if ($this->isRootedAtMailFacadeChainRoot($node->var)) {
                $argIndex = in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true) ? 1 : 0;
                $this->recordMailableSiteFromArg($node, $node->args, $argIndex, 'mail_chain', 'Mail::...->'.$methodName);

                return;
            }
        }

        if (in_array($methodName, self::NOTIFY_METHODS, true)) {
            // Notification::route(...)->notify(new X) and longer route chains
            // — opaque-receiver notify is accepted regardless, but we want
            // the `notification_chain` form label when the root is the
            // Notification facade.
            $form = $this->isRootedAtNotificationFacadeChainRoot($node->var)
                ? 'notification_chain'
                : 'notify_method';

            $this->recordNotificationSiteFromArg($node, $node->args, 0, $form, '->'.$methodName);
        }
    }

    /**
     * Walk down `->var` of a MethodCall chain. Returns true if the deepest
     * receiver is a StaticCall on the Mail facade whose method is one of
     * the chain-root methods (to/cc/bcc/locale/mailer).
     */
    private function isRootedAtMailFacadeChainRoot(Node\Expr $receiver): bool
    {
        $current = $receiver;
        while ($current instanceof Node\Expr\MethodCall) {
            $current = $current->var;
        }

        if (! $current instanceof Node\Expr\StaticCall) {
            return false;
        }
        if (! $current->class instanceof Node\Name) {
            return false;
        }
        if (! $current->name instanceof Node\Identifier) {
            return false;
        }

        $className = $current->class->toString();
        if (! Facades::matches($className, Facades::MAIL)) {
            return false;
        }

        return in_array($current->name->toString(), self::MAIL_CHAIN_ROOT_METHODS, true);
    }

    /**
     * Walk down `->var` of a MethodCall chain. Returns true if the deepest
     * receiver is a StaticCall on the Notification facade with method
     * `route` (possibly preceded by further `->route(...)` links).
     */
    private function isRootedAtNotificationFacadeChainRoot(Node\Expr $receiver): bool
    {
        $current = $receiver;
        while ($current instanceof Node\Expr\MethodCall) {
            $current = $current->var;
        }

        if (! $current instanceof Node\Expr\StaticCall) {
            return false;
        }
        if (! $current->class instanceof Node\Name) {
            return false;
        }
        if (! $current->name instanceof Node\Identifier) {
            return false;
        }

        $className = $current->class->toString();
        if (! Facades::matches($className, Facades::NOTIFICATION)) {
            return false;
        }

        return $current->name->toString() === 'route';
    }

    /**
     * Shared emission helper for mailable dispatch shapes.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  'mail_facade'|'mail_chain'  $form
     */
    private function recordMailableSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, string $form, string $callLabel): void
    {
        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, 'mailable', $callLabel);
    }

    /**
     * Shared emission helper for notification dispatch shapes.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  'notify_method'|'notification_facade'|'notification_chain'  $form
     */
    private function recordNotificationSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, string $form, string $callLabel): void
    {
        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, 'notification', $callLabel);
    }

    /**
     * Pull the target FQCN from $args[$argIndex] and emit either a resolved
     * site or an unresolved entry. Mirrors recordHelperOrFacade but
     * parameterised on the argument index and the (form, kind) labels.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  'mail_facade'|'mail_chain'|'notify_method'|'notification_facade'|'notification_chain'  $form
     * @param  'mailable'|'notification'  $kind
     */
    private function recordSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, string $form, string $kind, string $callLabel): void
    {
        if (! isset($args[$argIndex])) {
            return;
        }
        $arg = $args[$argIndex];
        if (! $arg instanceof Node\Arg) {
            return;
        }

        $resolved = $this->resolveStaticClass($arg->value);

        if ($resolved !== null) {
            if ($this->shouldSkipEmission()) {
                return;
            }
            $this->sites[] = [
                'classFqcn' => $this->currentClassFqcn(),
                'method' => $this->currentMethod(),
                'target' => $resolved,
                'form' => $form,
                'provisionalKind' => $kind,
                'file' => null,
                'line' => $callNode->getStartLine(),
                'confidence' => 'high',
            ];

            return;
        }

        if ($this->shouldSkipEmission()) {
            return;
        }

        $reason = $this->classifyUnresolvedReason($arg->value);
        $expression = $this->renderExpression($callNode, $callLabel);

        $this->unresolved[] = [
            'file' => null,
            'line' => $callNode->getStartLine(),
            'expression' => $expression,
            'reason' => $reason,
        ];
    }

    /**
     * Emit either a resolved site or an unresolved entry for the
     * helper/facade/bus_facade/job_helper forms (those that take the target
     * class in the first argument).
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  'helper'|'facade'|'job_helper'  $form
     * @param  'event'|'job'  $kind
     */
    private function recordHelperOrFacade(Node\Expr $callNode, array $args, string $form, string $kind, string $callLabel): void
    {
        if ($args === []) {
            // event() with zero args → silently skip (malformed).
            return;
        }

        $first = $args[0];
        if (! $first instanceof Node\Arg) {
            return;
        }

        $resolved = $this->resolveStaticClass($first->value);

        // Special case: ternary whose branches both resolve statically →
        // emit two sites instead of an unresolved.
        if ($resolved === null && $first->value instanceof Node\Expr\Ternary) {
            $ternary = $first->value;
            $ifBranch = $ternary->if;
            $elseBranch = $ternary->else;

            if ($ifBranch !== null) {
                $ifFqcn = $this->resolveStaticClass($ifBranch);
                $elseFqcn = $this->resolveStaticClass($elseBranch);

                if ($ifFqcn !== null && $elseFqcn !== null) {
                    $this->emitResolved($callNode, $ifFqcn, $form, $kind);
                    $this->emitResolved($callNode, $elseFqcn, $form, $kind);

                    return;
                }
            }
        }

        if ($resolved !== null) {
            $this->emitResolved($callNode, $resolved, $form, $kind);

            return;
        }

        // Unresolved — classify reason.
        if ($this->shouldSkipEmission()) {
            // Even unresolved entries are skipped for closure-internal sites
            // and top-level-script sites (skipped during emission).
            return;
        }

        $reason = $this->classifyUnresolvedReason($first->value);
        $expression = $this->renderExpression($callNode, $callLabel);

        $this->unresolved[] = [
            'file' => null,
            'line' => $callNode->getStartLine(),
            'expression' => $expression,
            'reason' => $reason,
        ];
    }

    /**
     * @param  'helper'|'facade'|'job_helper'  $form
     * @param  'event'|'job'  $kind
     */
    private function emitResolved(Node\Expr $callNode, string $targetFqcn, string $form, string $kind): void
    {
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = [
            'classFqcn' => $this->currentClassFqcn(),
            'method' => $this->currentMethod(),
            'target' => $targetFqcn,
            'form' => $form,
            'provisionalKind' => $kind,
            'file' => null,
            'line' => $callNode->getStartLine(),
            'confidence' => 'high',
        ];
    }

    private function shouldSkipEmission(): bool
    {
        if ($this->closureDepth > 0) {
            return true;
        }

        $classFqcn = $this->currentClassFqcn();
        if ($classFqcn === null) {
            return true;
        }

        return false;
    }

    private function currentClassFqcn(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[count($this->classStack) - 1]['class'];
    }

    private function currentMethod(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[count($this->classStack) - 1]['method'];
    }

    /**
     * Resolve a statically determinable target class FQCN from an expression.
     * Returns null if the expression is not a `new X(...)` or `X::class` form.
     */
    private function resolveStaticClass(?Node $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class'
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    private function classifyUnresolvedReason(Node $expr): string
    {
        if ($expr instanceof Node\Expr\Variable) {
            return 'dynamic_class_name';
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Expr\Variable) {
            return 'dynamic_class_name';
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), ['app', 'resolve'], true)
        ) {
            return 'container_resolution';
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'make'
        ) {
            return 'container_resolution';
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return 'string_concatenation';
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            return 'string_concatenation';
        }

        if ($expr instanceof Node\Expr\Ternary) {
            return 'conditional_dispatch';
        }

        if ($expr instanceof Node\Expr\Match_) {
            return 'conditional_dispatch';
        }

        return 'dynamic_class_name';
    }

    private function renderExpression(Node\Expr $callNode, string $callLabel): string
    {
        try {
            $rendered = $this->printer->prettyPrintExpr($callNode);
        } catch (\Throwable) {
            $rendered = $callLabel.'(...)';
        }

        // Collapse whitespace and truncate so we keep the schema entry compact.
        $rendered = trim((string) preg_replace('/\s+/', ' ', $rendered));
        if (strlen($rendered) > 80) {
            $rendered = substr($rendered, 0, 77).'...';
        }

        return $rendered;
    }

    /**
     * @return array<int, array{
     *     classFqcn: ?string,
     *     method: ?string,
     *     target: string,
     *     form: 'helper'|'facade'|'job_helper'|'dispatchable'|'mail_facade'|'mail_chain'|'notify_method'|'notification_facade'|'notification_chain',
     *     provisionalKind: 'event'|'job'|'ambiguous'|'mailable'|'notification',
     *     file: ?string,
     *     line: int,
     *     confidence: 'high'
     * }>
     */
    public function getSites(): array
    {
        return $this->sites;
    }

    /**
     * @return array<int, array{file: ?string, line: int, expression: string, reason: string}>
     */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }
}
