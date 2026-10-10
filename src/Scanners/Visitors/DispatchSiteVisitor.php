<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\DispatchOverrides;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\ChainModifierExtractor;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects statically resolvable dispatch sites in a parsed file.
 *
 * @internal
 */
final class DispatchSiteVisitor extends NodeVisitorAbstract
{
    /**
     * Mail terminal methods (facade and PendingMail): the mailable argument
     * index and the execution mode. `send` is plain: the mailable's own
     * ShouldQueue decides whether it is queued.
     */
    private const MAIL_TERMINALS = [
        'send' => [0, null],
        'sendNow' => [0, DispatchMode::SYNC],
        'queue' => [0, DispatchMode::PUSH],
        'onQueue' => [1, DispatchMode::PUSH],
        'queueOn' => [1, DispatchMode::PUSH],
        'later' => [1, DispatchMode::PUSH],
        'laterOn' => [2, DispatchMode::PUSH],
    ];

    private const MAIL_CHAIN_ROOT_METHODS = ['to', 'cc', 'bcc', 'locale', 'mailer'];

    /** Notification::send / sendNow; `send` is plain (ShouldQueue decides). */
    private const NOTIFICATION_FACADE_METHODS = ['send' => null, 'sendNow' => DispatchMode::SYNC];

    private const NOTIFY_METHODS = ['notify' => null, 'notifyNow' => DispatchMode::SYNC];

    /** Bus::* single-job methods and their execution mode (null = plain). */
    private const BUS_METHODS = [
        'dispatch' => null,
        'dispatchSync' => DispatchMode::SYNC,
        'dispatchNow' => DispatchMode::SYNC,
        'dispatchAfterResponse' => DispatchMode::AFTER_RESPONSE,
    ];

    /** Queue::* methods that push one job, mapped to the job argument index. */
    private const QUEUE_METHODS = ['push' => 0, 'pushOn' => 1, 'later' => 1, 'laterOn' => 2];

    /** Dispatchable static forms whose target is the static class itself. */
    private const DISPATCHABLE_METHODS = ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchSync', 'dispatchAfterResponse'];

    /** @var array<int, array{class: ?string, method: ?string}> */
    private array $classStack = [];

    /**
     * Stack of MethodCall nodes currently entered but not yet left. Parents
     * enter before children, so when a dispatch call is recorded on leaveNode
     * the wrapping outer-chain modifier MethodCalls (PendingDispatch form) are
     * still on this stack. Self-contained here to avoid mutating AstWalker's
     * shared traversal with a ParentConnectingVisitor.
     *
     * @var list<Node\Expr\MethodCall>
     */
    private array $methodCallStack = [];

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
        $this->methodCallStack = [];
        $this->closureDepth = 0;
        $this->sites = [];
        $this->unresolved = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $this->methodCallStack[] = $node;
        }

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
            // Pop AFTER handling: this MethodCall may itself be an outer-chain
            // modifier wrapping a dispatch call that was recorded earlier (its
            // child leaveNode ran first), so it had to stay on the stack while
            // that child resolved its overrides.
            array_pop($this->methodCallStack);
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

        if ($name === 'broadcast') {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::HELPER, DispatchKinds::EVENT, 'broadcast');

            return;
        }

        if ($name === 'broadcast_if' || $name === 'broadcast_unless') {
            // Conditional forms: $boolean is arg 0, the event is arg 1.
            $this->recordHelperOrFacade($node, array_slice($node->args, 1), DispatchForm::HELPER, DispatchKinds::EVENT, $name);

            return;
        }

        if ($name === 'dispatch') {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::JOB_HELPER, DispatchKinds::JOB, 'dispatch');

            return;
        }

        if ($name === 'dispatch_sync') {
            $this->recordHelperOrFacade($node, $node->args, DispatchForm::JOB_HELPER, DispatchKinds::JOB, 'dispatch_sync', DispatchMode::SYNC);
        }
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
            if (isset(self::MAIL_TERMINALS[$methodName])) {
                [$argIndex, $mode] = self::MAIL_TERMINALS[$methodName];
                $this->recordMailableSiteFromArg($node, $node->args, $argIndex, DispatchForm::MAIL_FACADE, 'Mail::'.$methodName, $mode);

                return;
            }

            // Chain roots (to/cc/etc.) have no target on the static call itself.
            return;
        }

        if (Facades::NOTIFICATION->matches($className)) {
            if (array_key_exists($methodName, self::NOTIFICATION_FACADE_METHODS)) {
                $this->recordNotificationSiteFromArg($node, $node->args, 1, DispatchForm::NOTIFICATION_FACADE, 'Notification::'.$methodName, self::NOTIFICATION_FACADE_METHODS[$methodName]);

                return;
            }

            return;
        }

        if (Facades::BUS->matches($className)) {
            if (in_array($methodName, ['chain', 'batch'], true)) {
                $this->recordJobList($node, $node->args, 'Bus::'.$methodName);
            } elseif (array_key_exists($methodName, self::BUS_METHODS)) {
                $this->recordHelperOrFacade($node, $node->args, DispatchForm::JOB_HELPER, DispatchKinds::JOB, 'Bus::'.$methodName, self::BUS_METHODS[$methodName]);
            }

            return;
        }

        if (Facades::QUEUE->matches($className)) {
            if ($methodName === 'bulk') {
                $this->recordJobList($node, $node->args, 'Queue::bulk', DispatchMode::PUSH);
            } elseif (isset(self::QUEUE_METHODS[$methodName])) {
                $this->recordSiteFromArg($node, $node->args, self::QUEUE_METHODS[$methodName], DispatchForm::FACADE, DispatchKinds::JOB, 'Queue::'.$methodName, mode: DispatchMode::PUSH);
            }

            return;
        }

        if (! in_array($methodName, self::DISPATCHABLE_METHODS, true)) {
            return;
        }

        // Facades have no conditional form — only the Dispatchable trait does.
        if (Facades::EVENT->matches($className)) {
            if ($methodName === 'dispatch') {
                $this->recordHelperOrFacade($node, $node->args, DispatchForm::FACADE, DispatchKinds::EVENT, 'Event::dispatch');
            }

            return;
        }

        // Dispatchable form: X::dispatch(...) / X::dispatchIf(...) / X::dispatchUnless(...).
        if ($this->shouldSkipResolved()) {
            return;
        }

        // The sync / after-response statics exist only on the Bus Dispatchable
        // trait (event dispatchables have no such forms), so they are jobs.
        $staticMode = match ($methodName) {
            'dispatchSync' => DispatchMode::SYNC,
            'dispatchAfterResponse' => DispatchMode::AFTER_RESPONSE,
            default => null,
        };

        // (c) outer PendingDispatch chain: `Job::dispatch($o)->onQueue('high')`.
        $outerLinks = $this->outerChainLinks($node);
        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $className,
            form: DispatchForm::DISPATCHABLE,
            provisionalKind: $staticMode === null ? DispatchKinds::AMBIGUOUS : DispatchKinds::JOB,
            file: null,
            line: $node->getStartLine(),
            confidence: 'high',
            overrides: $this->overridesFrom($outerLinks),
            mode: $staticMode ?? ChainModifierExtractor::mode($outerLinks),
            inClosure: $this->inClosure(),
        );
    }

    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        $methodName = $node->name->toString();

        if (isset(self::MAIL_TERMINALS[$methodName])
            && $this->isRootedAtFacadeChainRoot($node->var, Facades::MAIL, self::MAIL_CHAIN_ROOT_METHODS)
        ) {
            [$argIndex, $mode] = self::MAIL_TERMINALS[$methodName];
            $this->recordMailableSiteFromArg($node, $node->args, $argIndex, DispatchForm::MAIL_CHAIN, 'Mail::...->'.$methodName, $mode);

            return;
        }

        if (array_key_exists($methodName, self::NOTIFY_METHODS)) {
            // Opaque-receiver ->notify(...) is accepted; the chain-root walk
            // only changes the `form` label when rooted at Notification::route.
            $form = $this->isRootedAtFacadeChainRoot($node->var, Facades::NOTIFICATION, ['route'])
                ? DispatchForm::NOTIFICATION_CHAIN
                : DispatchForm::NOTIFY_METHOD;

            $this->recordNotificationSiteFromArg($node, $node->args, 0, $form, '->'.$methodName, self::NOTIFY_METHODS[$methodName]);
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
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     */
    private function recordMailableSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, string $callLabel, ?DispatchMode $mode = null): void
    {
        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, DispatchKinds::MAILABLE, $callLabel, mode: $mode);
    }

    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     */
    private function recordNotificationSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, string $callLabel, ?DispatchMode $mode = null): void
    {
        // The facade form (Notification::send/sendNow) takes an optional channel
        // filter at $argIndex + 1; the notify-method form has no such argument.
        $channels = $form === DispatchForm::NOTIFICATION_FACADE
            ? $this->channelFilterFrom($args, $argIndex + 1)
            : null;

        $this->recordSiteFromArg($callNode, $args, $argIndex, $form, DispatchKinds::NOTIFICATION, $callLabel, $channels, $mode);
    }

    /**
     * Resolve a static channel filter argument to a channel list. Returns null
     * when the argument is missing, not a plain Arg, non-literal, or an empty
     * array literal (treated as "no filter").
     *
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     * @return list<string>|null
     */
    private function channelFilterFrom(array $args, int $index): ?array
    {
        if (! isset($args[$index])) {
            return null;
        }
        $arg = $args[$index];
        if (! $arg instanceof Node\Arg) {
            return null;
        }

        $channels = AstHelpers::channelList($arg->value);
        if ($channels === null || $channels === []) {
            return null;
        }

        return $channels;
    }

    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     * @param  list<string>|null  $channels
     */
    private function recordSiteFromArg(Node\Expr $callNode, array $args, int $argIndex, DispatchForm $form, DispatchKinds $kind, string $callLabel, ?array $channels = null, ?DispatchMode $mode = null): void
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
            if ($this->shouldSkipResolved()) {
                return;
            }

            // (a) inner argument-instance chain + (b) Mail/Notification facade
            // receiver chain. The receiver chain only contributes links when
            // $callNode is a MethodCall (the `Mail::to(...)->locale(...)->send`
            // form); for a plain StaticCall receiver links resolve to none.
            $innerLinks = $this->innerChainLinks($arg->value);
            $receiverLinks = $callNode instanceof Node\Expr\MethodCall
                ? $this->mailReceiverChainLinks($callNode->var)
                : [];

            $this->sites[] = new DispatchSiteRecord(
                classFqcn: $this->currentClassFqcn(),
                method: $this->currentMethod(),
                target: $resolved,
                form: $form,
                provisionalKind: $kind,
                file: null,
                line: $callNode->getStartLine(),
                confidence: 'high',
                overrides: $this->overridesFrom($innerLinks, $receiverLinks),
                mode: $mode,
                channels: $channels,
                inClosure: $this->inClosure(),
            );

            return;
        }

        if ($this->shouldSkipUnresolved()) {
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
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     */
    private function recordHelperOrFacade(Node\Expr $callNode, array $args, DispatchForm $form, DispatchKinds $kind, string $callLabel, ?DispatchMode $mode = null): void
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
                    $this->emitResolved($callNode, $ifFqcn, $form, $kind, $ifBranch, $mode);
                    $this->emitResolved($callNode, $elseFqcn, $form, $kind, $elseBranch, $mode);

                    return;
                }
            }
        }

        if ($resolved !== null) {
            $this->emitResolved($callNode, $resolved, $form, $kind, $first->value, $mode);

            return;
        }

        if ($this->shouldSkipUnresolved()) {
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

    /**
     * Bus::chain([...]) / Bus::batch([...]): one job site per literal item; a
     * non-literal list or item is recorded as unresolved.
     *
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     */
    private function recordJobList(Node\Expr $callNode, array $args, string $callLabel, ?DispatchMode $mode = null): void
    {
        $first = $args[0] ?? null;
        if (! $first instanceof Node\Arg) {
            return;
        }

        if (! $first->value instanceof Node\Expr\Array_) {
            $this->recordUnresolvedList($callNode, $first->value, $callLabel);

            return;
        }

        foreach ($first->value->items as $item) {
            $value = $item->value;
            $resolved = AstHelpers::resolveStaticClass($value);
            if ($resolved !== null && ! $item->unpack) {
                $this->emitResolved($callNode, $resolved, DispatchForm::JOB_HELPER, DispatchKinds::JOB, $value, $mode);

                continue;
            }

            $this->recordUnresolvedList($callNode, $value, $callLabel);
        }
    }

    private function recordUnresolvedList(Node\Expr $callNode, Node\Expr $value, string $callLabel): void
    {
        if ($this->shouldSkipUnresolved()) {
            return;
        }

        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $callNode->getStartLine(),
            expression: $this->renderExpression($callNode, $callLabel),
            reason: $this->classifyUnresolvedReason($value),
        );
    }

    private function emitResolved(Node\Expr $callNode, string $targetFqcn, DispatchForm $form, DispatchKinds $kind, ?Node\Expr $argValue = null, ?DispatchMode $mode = null): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        // (a) inner argument-instance chain + (c) outer PendingDispatch chain
        // wrapping `dispatch(...)` / `event(...)`. Inner links applied first so
        // an outer modifier wins on a same-key conflict.
        $innerLinks = $argValue !== null ? $this->innerChainLinks($argValue) : [];
        $outerLinks = $this->outerChainLinks($callNode);

        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $targetFqcn,
            form: $form,
            provisionalKind: $kind,
            file: null,
            line: $callNode->getStartLine(),
            confidence: 'high',
            overrides: $this->overridesFrom($innerLinks, $outerLinks),
            // `->afterResponse()` exists only on PendingDispatch, never on event().
            mode: $mode ?? ($kind === DispatchKinds::EVENT ? null : ChainModifierExtractor::mode($outerLinks)),
            inClosure: $this->inClosure(),
        );
    }

    private function inClosure(): bool
    {
        return $this->closureDepth > 0;
    }

    /**
     * Resolved sites emit inside closures too, tagged via {@see inClosure()}.
     * Cross-link decides ownership: a registration closure (listener, route)
     * keeps them, a pass-through closure hands them to the enclosing method.
     * A class-less site is only useful inside a closure (route files).
     */
    private function shouldSkipResolved(): bool
    {
        return $this->currentClassFqcn() === null && ! $this->inClosure();
    }

    private function shouldSkipUnresolved(): bool
    {
        return $this->currentClassFqcn() === null && ! $this->inClosure();
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
            return UnresolvedReason::DYNAMIC_CLASS_NAME->value;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Expr\Variable) {
            return UnresolvedReason::DYNAMIC_CLASS_NAME->value;
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), ['app', 'resolve'], true)
        ) {
            return UnresolvedReason::CONTAINER_RESOLUTION->value;
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'make'
        ) {
            return UnresolvedReason::CONTAINER_RESOLUTION->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return UnresolvedReason::STRING_CONCATENATION->value;
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            return UnresolvedReason::STRING_CONCATENATION->value;
        }

        if ($expr instanceof Node\Expr\Ternary) {
            return UnresolvedReason::CONDITIONAL_DISPATCH->value;
        }

        if ($expr instanceof Node\Expr\Match_) {
            return UnresolvedReason::CONDITIONAL_DISPATCH->value;
        }

        return UnresolvedReason::DYNAMIC_CLASS_NAME->value;
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

    /**
     * Collect the fluent `->method()` links on the dispatched-instance argument
     * (position a), e.g. the `delay`/`onQueue` links in
     * `dispatch((new Job)->delay(60)->onQueue('high'))`. Walks `->var` down to
     * the `New_`/`ClassConstFetch` root, then returns the links in source order.
     *
     * @return list<Node\Expr\MethodCall>
     */
    private function innerChainLinks(Node\Expr $argValue): array
    {
        $links = [];
        $current = $argValue;
        while ($current instanceof Node\Expr\MethodCall) {
            $links[] = $current;
            $current = $current->var;
        }

        // Walked outermost-first; reverse to source (innermost-first) order.
        return array_reverse($links);
    }

    /**
     * Collect the outer PendingDispatch chain links wrapping a dispatch call
     * (position c), e.g. `Job::dispatch($o)->onQueue('q')->delay(60)`. The
     * modifier MethodCalls are ancestors of $dispatchNode and are still on
     * $this->methodCallStack while $dispatchNode resolves. Selects those whose
     * receiver chain (`->var`) reaches $dispatchNode.
     *
     * @return list<Node\Expr\MethodCall>
     */
    private function outerChainLinks(Node\Expr $dispatchNode): array
    {
        $links = [];
        foreach ($this->methodCallStack as $candidate) {
            if ($this->receiverChainReaches($candidate->var, $dispatchNode)) {
                $links[] = $candidate;
            }
        }

        // Stack is parent-(outermost-)first; reverse to source order so the
        // link nearest the dispatch call is applied first.
        return array_reverse($links);
    }

    /**
     * Collect modifier links in a Mail facade receiver chain (position b),
     * e.g. `Mail::to($u)->locale('fr')->mailer('ses')->send($m)` — the
     * modifiers sit in $callNode's receiver chain, between the terminal
     * send/queue/later and the `Mail::...` static-call root.
     *
     * @return list<Node\Expr\MethodCall>
     */
    private function mailReceiverChainLinks(Node\Expr $receiver): array
    {
        $links = [];
        $current = $receiver;
        while ($current instanceof Node\Expr\MethodCall) {
            $links[] = $current;
            $current = $current->var;
        }

        return array_reverse($links);
    }

    /** True when walking $receiver down `->var` reaches $target. */
    private function receiverChainReaches(Node\Expr $receiver, Node\Expr $target): bool
    {
        $current = $receiver;
        while (true) {
            if ($current === $target) {
                return true;
            }
            if ($current instanceof Node\Expr\MethodCall) {
                $current = $current->var;

                continue;
            }

            return false;
        }
    }

    /**
     * Combine link lists (in the given order) into one DispatchOverrides.
     * Later links win per key; passing inner links before outer links makes
     * an outer modifier take precedence over a same-key inner one.
     *
     * @param  list<Node\Expr\MethodCall>  ...$linkLists
     */
    private function overridesFrom(array ...$linkLists): DispatchOverrides
    {
        return ChainModifierExtractor::extract(array_merge(...$linkLists));
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
