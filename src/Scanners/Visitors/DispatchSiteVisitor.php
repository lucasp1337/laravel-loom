<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects statically resolvable dispatch sites in a parsed file.
 */
final class DispatchSiteVisitor extends NodeVisitorAbstract
{
    private const MAIL_OUTERMOST_METHODS_ARG0 = ['send', 'queue'];

    /** Mail::later($delay, $mailable) — target is at index 1. */
    private const MAIL_OUTERMOST_METHODS_ARG1 = ['later'];

    private const MAIL_CHAIN_ROOT_METHODS = ['to', 'cc', 'bcc', 'locale', 'mailer'];

    private const NOTIFICATION_FACADE_METHODS = ['send', 'sendNow'];

    private const NOTIFY_METHODS = ['notify', 'notifyNow'];

    /** @var array<int, array{class: ?string, method: ?string}> */
    private array $classStack = [];

    private int $closureDepth = 0;

    /** @var list<DispatchSiteRecord> */
    private array $sites = [];

    /** @var list<UnresolvedDispatchRecord> */
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
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::HELPER, DispatchKinds::EVENT, 'event');

            return;
        }

        if ($name === 'dispatch') {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::JOB_HELPER, DispatchKinds::JOB, 'dispatch');
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

        if (Facades::MAIL->matches($className)) {
            if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG0, true)) {
                $this->recordMailableSiteFromArg($node, $node->args, 0, DispatchForm::MAIL_FACADE, 'Mail::'.$methodName);

                return;
            }
            if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true)) {
                $this->recordMailableSiteFromArg($node, $node->args, 1, DispatchForm::MAIL_FACADE, 'Mail::'.$methodName);

                return;
            }

            // Chain roots (to/cc/etc.) have no target on the static call itself.
            return;
        }

        if (Facades::NOTIFICATION->matches($className)) {
            if (in_array($methodName, self::NOTIFICATION_FACADE_METHODS, true)) {
                $this->recordNotificationSiteFromArg($node, $node->args, 1, DispatchForm::NOTIFICATION_FACADE, 'Notification::'.$methodName);

                return;
            }

            return;
        }

        // dispatchSync / dispatchNow intentionally skipped.
        if ($methodName !== 'dispatch') {
            return;
        }

        if (Facades::EVENT->matches($className)) {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::FACADE, DispatchKinds::EVENT, 'Event::dispatch');

            return;
        }

        if (Facades::BUS->matches($className)) {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::JOB_HELPER, DispatchKinds::JOB, 'Bus::dispatch');

            return;
        }

        // Dispatchable form: X::dispatch(...).
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $className,
            form: DispatchForm::DISPATCHABLE,
            provisionalKind: DispatchKinds::AMBIGUOUS,
            file: null,
            line: $node->getStartLine(),
            confidence: 'high',
        );
    }

    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        $methodName = $node->name->toString();

        if (in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG0, true)
            || in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true)
        ) {
            if ($this->isRootedAtFacadeChainRoot($node->var, Facades::MAIL, self::MAIL_CHAIN_ROOT_METHODS)) {
                $argIndex = in_array($methodName, self::MAIL_OUTERMOST_METHODS_ARG1, true) ? 1 : 0;
                $this->recordMailableSiteFromArg($node, $node->args, $argIndex, DispatchForm::MAIL_CHAIN, 'Mail::...->'.$methodName);

                return;
            }
        }

        if (in_array($methodName, self::NOTIFY_METHODS, true)) {
            // Opaque-receiver ->notify(...) is accepted; the chain-root walk
            // only changes the `form` label when rooted at Notification::route.
            $form = $this->isRootedAtFacadeChainRoot($node->var, Facades::NOTIFICATION, ['route'])
                ? DispatchForm::NOTIFICATION_CHAIN
                : DispatchForm::NOTIFY_METHOD;

            $this->recordNotificationSiteFromArg($node, $node->args, 0, $form, '->'.$methodName);
        }
    }

    /**
     * @param  list<string>  $rootMethods
     */
    private function isRootedAtFacadeChainRoot(Node\Expr $receiver, Facades $facade, array $rootMethods): bool
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
        if (! $facade->matches($current->class->toString())) {
            return false;
        }

        return in_array($current->name->toString(), $rootMethods, true);
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function recordMailableSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, string $callLabel): void
    {
        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, DispatchKinds::MAILABLE, $callLabel);
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function recordNotificationSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, string $callLabel): void
    {
        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, DispatchKinds::NOTIFICATION, $callLabel);
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function recordSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, DispatchKinds $kind, string $callLabel): void
    {
        if (! isset($args[$argIndex])) {
            return;
        }
        $arg = $args[$argIndex];
        if (! $arg instanceof Node\Arg) {
            return;
        }

        $resolved = AstHelpers::resolveStaticClass($arg->value);

        if ($resolved !== null) {
            if ($this->shouldSkipEmission()) {
                return;
            }
            $this->sites[] = new DispatchSiteRecord(
                classFqcn: $this->currentClassFqcn(),
                method: $this->currentMethod(),
                target: $resolved,
                form: $form,
                provisionalKind: $kind,
                file: null,
                line: $callNode->getStartLine(),
                confidence: 'high',
            );

            return;
        }

        if ($this->shouldSkipEmission()) {
            return;
        }

        $reason = $this->classifyUnresolvedReason($arg->value);
        $expression = $this->renderExpression($callNode, $callLabel);

        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $callNode->getStartLine(),
            expression: $expression,
            reason: $reason,
        );
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function recordHelperOrFacade(Node\Expr $callNode, array $args, DispatchForm $form, DispatchKinds $kind, string $callLabel): void
    {
        if ($args === []) {
            return;
        }

        $first = $args[0];
        if (! $first instanceof Node\Arg) {
            return;
        }

        $resolved = AstHelpers::resolveStaticClass($first->value);

        // Ternary with two statically resolvable branches → emit both.
        if ($resolved === null && $first->value instanceof Node\Expr\Ternary) {
            $ternary = $first->value;
            $ifBranch = $ternary->if;
            $elseBranch = $ternary->else;

            if ($ifBranch !== null) {
                $ifFqcn = AstHelpers::resolveStaticClass($ifBranch);
                $elseFqcn = AstHelpers::resolveStaticClass($elseBranch);

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

        if ($this->shouldSkipEmission()) {
            return;
        }

        $reason = $this->classifyUnresolvedReason($first->value);
        $expression = $this->renderExpression($callNode, $callLabel);

        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $callNode->getStartLine(),
            expression: $expression,
            reason: $reason,
        );
    }

    private function emitResolved(Node\Expr $callNode, string $targetFqcn, DispatchForm $form, DispatchKinds $kind): void
    {
        if ($this->shouldSkipEmission()) {
            return;
        }

        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $targetFqcn,
            form: $form,
            provisionalKind: $kind,
            file: null,
            line: $callNode->getStartLine(),
            confidence: 'high',
        );
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

    /** @return 'dynamic_class_name'|'container_resolution'|'string_concatenation'|'conditional_dispatch' */
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

        $rendered = trim((string) preg_replace('/\s+/', ' ', $rendered));
        if (strlen($rendered) > 80) {
            $rendered = substr($rendered, 0, 77).'...';
        }

        return $rendered;
    }

    /** @return list<DispatchSiteRecord> */
    public function getSites(): array
    {
        return $this->sites;
    }

    /** @return list<UnresolvedDispatchRecord> */
    public function getUnresolved(): array
    {
        return $this->unresolved;
    }
}
