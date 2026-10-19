<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Arr;
use Lucasp\Loom\Dto\ClassArgument;
use Lucasp\Loom\Dto\ClassArgumentCall;
use Lucasp\Loom\Dto\DispatchOverrides;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\MethodCallEdge;
use Lucasp\Loom\Dto\ParamDispatchRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Scanners\Dispatch\DispatchRule;
use Lucasp\Loom\Scanners\Dispatch\DispatchRuleMatcher;
use Lucasp\Loom\Scanners\Dispatch\DispatchTarget;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\CallKind;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\LocalAssignments;
use Lucasp\Loom\Support\Ast\ReceiverClass;
use Lucasp\Loom\Support\Ast\RelativeClassName;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\ChainModifierExtractor;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects statically resolvable dispatch sites in a parsed file.
 *
 * Shapes are matched by {@see DispatchRuleMatcher} over {@see DispatchRules};
 * this visitor owns the scope: it keeps a class and method stack and a closure
 * depth, so a site inside any closure carries `inClosure`, and it decides
 * between a resolved site and an unresolved dispatch. Reads on `leaveNode`, so
 * the inner class reference is already resolved.
 *
 * A target that is a parameter of its method (`new $mailClass`) stays
 * unresolved here and is also kept as a {@see ParamDispatchRecord}; calls that
 * pass a class to a receiver of known class ({@see ReceiverClass}) are kept as
 * {@see ClassArgumentCall}s. The scanner pairs the two across files.
 *
 * @internal
 */
final class DispatchSiteVisitor extends CollectingVisitor
{
    use TracksClassScope;

    /**
     * Stack of MethodCall nodes currently entered but not yet left. Parents
     * enter before children, so when a dispatch call is recorded on leaveNode
     * the wrapping outer-chain modifier MethodCalls (PendingDispatch form) are
     * still on this stack. Self-contained here to avoid mutating AstWalker's
     * shared traversal with a ParentConnectingVisitor.
     *
     * @var list<CallSite>
     */
    private array $methodCallStack = [];

    private int $closureDepth = 0;

    /**
     * One frame per function body being walked: the dispatch arguments that are
     * a bare variable, resolved when the body is left and every assignment is known.
     *
     * @var list<list<array{name: string, resolve: \Closure(Node\Expr): bool, fallback: \Closure(): void}>>
     */
    private array $pendingByFunction = [];

    /** @var list<Node\FunctionLike> function bodies being walked, innermost last */
    private array $functions = [];

    /** @var list<ParamDispatchRecord> */
    private array $paramDispatches = [];

    /** @var list<ClassArgumentCall> */
    private array $classArgumentCalls = [];

    /** @var list<DispatchSiteRecord> */
    private array $sites = [];

    /** @var list<UnresolvedDispatchRecord> */
    private array $unresolved = [];

    /** @var array<string, MethodCallEdge> same-class calls, keyed to drop repeats */
    private array $callEdges = [];

    private PrettyPrinter $printer;

    private DispatchRuleMatcher $matcher;

    public function __construct(?DispatchRuleMatcher $matcher = null)
    {
        $this->printer = new PrettyPrinter;
        $this->matcher = $matcher ?? DispatchRuleMatcher::forDispatchSites();
    }

    protected function reset(): void
    {
        $this->methodCallStack = [];
        $this->closureDepth = 0;
        $this->pendingByFunction = [];
        $this->functions = [];
        $this->paramDispatches = [];
        $this->classArgumentCalls = [];
        $this->sites = [];
        $this->unresolved = [];
        $this->callEdges = [];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $site = CallSite::of($node);
            if ($site !== null) {
                $this->methodCallStack[] = $site;
            }
        }

        $this->enterClassScope($node);

        if ($node instanceof Node\FunctionLike) {
            $this->pendingByFunction[] = [];
            $this->functions[] = $node;
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike) {
            // The deferred fallbacks still see this body as the innermost function
            $this->resolvePending($node);
            array_pop($this->functions);
        }

        $call = CallSite::of($node);
        if ($call !== null) {
            $this->recordSameClassCall($call);
            $this->recordClassArguments($call);
            $rule = $this->matcher->match($call);
            match (true) {
                // A row of the table
                $rule !== null => $this->record($call, $rule),
                // `Bus::$method($job)`: an owned facade with a dynamic method name
                $this->matcher->dynamicFacadeCall($call) !== null => $this->recordDynamicFacadeCall($call),
                // Anything else is not a dispatch
                default => null,
            };
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            // Pop AFTER handling: this MethodCall may itself be an outer-chain
            // modifier wrapping a dispatch call that was recorded earlier (its
            // child leaveNode ran first), so it had to stay on the stack while
            // that child resolved its overrides.
            array_pop($this->methodCallStack);
        }

        $this->leaveClassScope($node);

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth = max(0, $this->closureDepth - 1);
        }

        return null;
    }

    /** `$this->helper()`, `self::helper()`, `static::helper()` inside a method: an edge to the helper. */
    private function recordSameClassCall(CallSite $call): void
    {
        $caller = $this->currentMethod();
        $class = $this->currentClassFqcn();
        $callee = $call->name();
        if ($caller === null || $class === null || $callee === null) {
            return;
        }

        $receiver = $call->receiver();
        $sameClass = match ($call->kind()) {
            // `$this->helper()`
            CallKind::METHOD => $receiver instanceof Node\Expr\Variable && $receiver->name === 'this',
            // `self::helper()` / `static::helper()`; `parent::` leaves the class
            CallKind::STATIC => $receiver instanceof Node\Name
                && in_array(RelativeClassName::fromName($receiver->toString()), [RelativeClassName::SELF, RelativeClassName::STATIC], true),
            CallKind::FUNCTION, CallKind::INSTANTIATION => false,
        };
        if (! $sameClass) {
            return;
        }

        $this->callEdges[$class.'::'.$caller.'>'.$callee] = new MethodCallEdge($class, $caller, $callee);
    }

    /**
     * `Bus::{'dispatch'}($job)`, or `Bus::$method($job)` with `$method`
     * assigned one string literal in this body, read as that method; any other
     * method name is an unresolved `dynamic_method_name`.
     */
    private function recordDynamicFacadeCall(CallSite $call): void
    {
        $node = $call->node();
        $name = $node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Expr ? $this->literalValue($node->name) : null;

        // A literal name: the row it names, or no dispatch when the table has none
        if ($name !== null) {
            $rule = $this->matcher->match($call, $name);
            if ($rule !== null) {
                $this->record($call, $rule);
            }

            return;
        }

        $this->recordUnresolvedAt($call, ($call->className() ?? '').'::$method', UnresolvedReason::DYNAMIC_METHOD_NAME);
    }

    /** A string literal, or a variable this body assigns one string literal exactly once. */
    private function literalValue(Node\Expr $expr): ?string
    {
        $function = $this->currentFunction();
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && $function !== null) {
            $expr = LocalAssignments::of($function)->singleValue($expr->name);
        }

        return Literal::string($expr);
    }

    private function currentFunction(): ?Node\FunctionLike
    {
        return $this->functions === [] ? null : $this->functions[count($this->functions) - 1];
    }

    /**
     * A method call passing `X::class` or `new X` to a receiver whose class the
     * source names (see {@see ReceiverClass}); a dispatch on that parameter in
     * the called method makes it a site.
     */
    private function recordClassArguments(CallSite $call): void
    {
        $method = $call->name();
        $receiver = $call->receiver();
        if ($method === null || $receiver === null || ! in_array($call->kind(), [CallKind::METHOD, CallKind::STATIC], true) || $this->shouldSkipResolved()) {
            return;
        }

        $arguments = [];
        $spread = false;
        foreach ($call->args()->all() as $arg) {
            // After `...$xs` a position no longer names a parameter; named arguments still do
            $spread = $spread || $arg->unpacked;
            $target = $arg->unpacked || ($spread && $arg->name === null) ? null : $this->classRef($arg->value);
            if ($target !== null) {
                $arguments[] = new ClassArgument($arg->position, $arg->name, $target);
            }
        }
        if ($arguments === []) {
            return;
        }

        $receiverClass = ReceiverClass::of($receiver, $this->currentClassNode(), $this->currentFunction(), fn (string $name): ?string => $this->resolveClassName($name));
        if ($receiverClass === null) {
            return;
        }

        $this->classArgumentCalls[] = new ClassArgumentCall(
            callerClass: $this->currentClassFqcn(),
            callerMethod: $this->currentMethod(),
            receiverClass: Fqcn::normalize($receiverClass),
            method: $method,
            arguments: $arguments,
            line: $call->line(),
            inClosure: $this->inClosure(),
            expression: $this->renderExpression($call->node(), $method),
        );
    }

    /**
     * `new $class`, `$event` or `$class::dispatch()` where the variable is a
     * parameter of the enclosing method that its body never writes: kept so a
     * caller passing a class becomes a site. The unresolved entry stays, since
     * callers Loom cannot see may pass anything.
     */
    private function recordParamDispatch(CallSite $call, Node\Expr $value, DispatchRule $rule): void
    {
        $variable = $value instanceof Node\Expr\New_ ? $value->class : $value;
        $function = $this->currentFunction();
        $class = $this->currentClassFqcn();
        $method = $this->currentMethod();
        if (! $variable instanceof Node\Expr\Variable || ! is_string($variable->name) || ! $function instanceof Node\Stmt\ClassMethod || $class === null || $method === null) {
            return;
        }

        foreach ($function->getParams() as $position => $param) {
            if ($param->variadic || ! $param->var instanceof Node\Expr\Variable || $param->var->name !== $variable->name) {
                continue;
            }
            if (LocalAssignments::of($function)->writes($variable->name)) {
                return;
            }

            $outerLinks = $this->outerChainLinks($call);
            $this->paramDispatches[] = new ParamDispatchRecord(
                classFqcn: $class,
                method: $method,
                position: $position,
                parameter: $variable->name,
                form: $rule->form,
                kind: $rule->kind,
                mode: $rule->mode ?? ($rule->kind === DispatchKinds::EVENT ? null : ChainModifierExtractor::mode($outerLinks)),
                overrides: $this->overridesFrom($outerLinks, $this->chainRootLink($call), $call->receiverLinks()),
            );

            return;
        }
    }

    /**
     * The facade call a method chain starts from, as a link: `Mail::mailer('postmark')`
     * in `Mail::mailer('postmark')->to($u)->send($m)`. Empty for any other receiver.
     *
     * @return list<CallSite>
     */
    private function chainRootLink(CallSite $call): array
    {
        $root = $call->chainRoot();

        return $root !== null && $root->kind() === CallKind::STATIC && $root->node() !== $call->node() ? [$root] : [];
    }

    /** `'App\Jobs\Ping@handle'` or `'App\Jobs\Ping'` where the row reads a class string. */
    private function classString(DispatchRule $rule, Node\Expr $value): ?string
    {
        $string = $rule->classString ? Literal::string($value) : null;
        if ($string === null) {
            return null;
        }

        $class = Fqcn::normalize(Fqcn::splitAtMember($string)[0] ?? $string);

        return preg_match('/^[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', $class) === 1 ? $class : null;
    }

    private function record(CallSite $call, DispatchRule $rule): void
    {
        match ($rule->target) {
            // `event($e)`, `Bus::dispatch($j)`: one argument, ternary-aware
            DispatchTarget::PENDING_ARGUMENT => $this->recordHelperOrFacade($call, $rule),
            // `Mail::send($m)`, `Queue::push($j)`: one argument, receiver chain
            DispatchTarget::ARGUMENT => $this->recordSiteFromArg($call, $rule),
            // `Bus::chain([...])`: one site per array item
            DispatchTarget::LIST_ITEMS => $this->recordJobList($call, $rule),
            // `Job::dispatch()`: the class itself
            DispatchTarget::STATIC_CLASS => $this->recordDispatchable($call, $rule),
            // `Mail::raw($text, $cb)`: no mailable class at all
            DispatchTarget::VIEW_MAIL => $this->recordUnresolvedWithReason($call, $rule, UnresolvedReason::VIEW_MAIL),
        };
    }

    private function recordDispatchable(CallSite $call, DispatchRule $rule): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        // `$class::dispatch()`: a class held in a variable
        $receiver = $call->receiver();
        if ($call->hasDynamicClass() && $receiver instanceof Node\Expr) {
            $this->recordUnresolved($call, $receiver, $rule);

            return;
        }

        $className = $this->resolveClassName($call->className());
        if ($className === null) {
            // `self::` in a trait or anonymous class, `parent::` with no parent: no class to name
            $this->recordUnresolvedWithReason($call, $rule, UnresolvedReason::RELATIVE_CLASS_NAME);

            return;
        }

        // Outer PendingDispatch chain: `Job::dispatch($o)->onQueue('high')`.
        $outerLinks = $this->outerChainLinks($call);
        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $className,
            form: $rule->form,
            provisionalKind: $rule->kind,
            file: null,
            line: $call->line(),
            confidence: Confidence::HIGH->value,
            overrides: $this->overridesFrom($outerLinks),
            mode: $rule->mode ?? ChainModifierExtractor::mode($outerLinks),
            inClosure: $this->inClosure(),
            expression: $this->renderExpression($call->node(), $rule->label()),
        );
    }

    /**
     * Resolve a static channel filter argument to a channel list. Returns null
     * when the argument is missing, not a plain Arg, non-literal, or an empty
     * array literal (treated as "no filter").
     *
     * @return list<string>|null
     */
    private function channelFilterFrom(Args $args, int $index): ?array
    {
        $value = $args->valueAt($index);
        if ($value === null) {
            return null;
        }

        $channels = ValueLists::channels($value);
        if ($channels === null || $channels === []) {
            return null;
        }

        return $channels;
    }

    private function recordSiteFromArg(CallSite $call, DispatchRule $rule): void
    {
        $argValue = $call->args()->valueAt($rule->argIndex);
        if ($argValue === null) {
            return;
        }

        $resolved = $this->classRef($argValue) ?? $this->classString($rule, $argValue);

        if ($resolved !== null) {
            $this->emitFromArgument($call, $rule, $resolved, $argValue);

            return;
        }

        if ($this->deferVariable($call, $rule, $argValue, fn (Node\Expr $value): bool => $this->emitTargets($value, fn (string $target, Node\Expr $node) => $this->emitFromArgument($call, $rule, $target, $node)))) {
            return;
        }

        $this->recordUnresolved($call, $argValue, $rule);
    }

    /** One site for an argument-form call (`Mail::send($m)`, `$u->notify($n)`), with the receiver-chain overrides. */
    private function emitFromArgument(CallSite $call, DispatchRule $rule, string $target, Node\Expr $argValue): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        // Argument-instance chain + Mail/Notification receiver chain
        // (`Mail::to(...)->locale(...)->send(...)`); a static call has no
        // receiver links.
        $innerLinks = CallChain::methodLinks($argValue);
        $receiverLinks = [...$this->chainRootLink($call), ...$call->receiverLinks()];

        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $target,
            form: $rule->form,
            provisionalKind: $rule->kind,
            file: null,
            line: $call->line(),
            confidence: Confidence::HIGH->value,
            overrides: $this->overridesFrom($innerLinks, $receiverLinks),
            mode: $rule->mode,
            // Only the facade form takes a channel filter argument.
            channels: $rule->channelsAt !== null ? $this->channelFilterFrom($call->args(), $rule->channelsAt) : null,
            inClosure: $this->inClosure(),
            expression: $this->renderExpression($call->node(), $rule->label()),
        );
    }

    /**
     * Defer a dispatch argument that is a bare local variable until its function
     * body has been walked: if the body assigns it exactly once, the assigned
     * expression stands in for it ({@see resolvePending()}). False when the
     * argument is not such a variable and the caller must report it now.
     *
     * @param  \Closure(Node\Expr): bool  $resolve  emits for the assigned expression; false when it cannot
     * @param  UnresolvedReason|null  $reason  the reason to report when it stays unresolved; null classifies the variable
     */
    private function deferVariable(CallSite $call, DispatchRule $rule, Node\Expr $value, \Closure $resolve, ?UnresolvedReason $reason = null): bool
    {
        if (! $value instanceof Node\Expr\Variable || ! is_string($value->name) || $this->pendingByFunction === [] || $this->shouldSkipUnresolved()) {
            return false;
        }

        $this->pendingByFunction[count($this->pendingByFunction) - 1][] = [
            'name' => $value->name,
            'resolve' => $resolve,
            'fallback' => $reason !== null
                ? fn () => $this->recordUnresolvedWithReason($call, $rule, $reason)
                : fn () => $this->recordUnresolved($call, $value, $rule),
        ];

        return true;
    }

    /** Resolve the variables deferred in the function body being left. */
    private function resolvePending(Node\FunctionLike $function): void
    {
        $pending = array_pop($this->pendingByFunction) ?? [];
        if ($pending === []) {
            return;
        }

        $assignments = LocalAssignments::of($function);
        foreach ($pending as $item) {
            $value = $assignments->singleValue($item['name']);
            if ($value === null || ! $item['resolve']($value)) {
                $item['fallback']();
            }
        }
    }

    /**
     * Emit one site per class a value names: `new X`, `X::class`, or a ternary
     * whose branches both do (recursively). False, emitting nothing, otherwise.
     *
     * @param  \Closure(string, Node\Expr): void  $emit
     */
    private function emitTargets(Node\Expr $value, \Closure $emit): bool
    {
        $targets = $this->targetsOf($value);
        if ($targets === null) {
            return false;
        }

        foreach ($targets as [$target, $node]) {
            $emit($target, $node);
        }

        return true;
    }

    /** @return list<array{0: string, 1: Node\Expr}>|null */
    private function targetsOf(Node\Expr $value): ?array
    {
        $single = $this->classRef($value);
        if ($single !== null) {
            return [[$single, $value]];
        }

        if (! $value instanceof Node\Expr\Ternary || $value->if === null) {
            return null;
        }

        $then = $this->targetsOf($value->if);
        $else = $this->targetsOf($value->else);

        return $then !== null && $else !== null ? [...$then, ...$else] : null;
    }

    private function recordHelperOrFacade(CallSite $call, DispatchRule $rule): void
    {
        $first = $call->args()->valueAt($rule->argIndex);
        if ($first === null) {
            return;
        }

        $resolved = $this->classRef($first);

        // Ternary with two statically resolvable branches: emit both.
        if ($resolved === null && $first instanceof Node\Expr\Ternary) {
            $ifBranch = $first->if;

            if ($ifBranch !== null) {
                $ifFqcn = $this->classRef($ifBranch);
                $elseFqcn = $this->classRef($first->else);

                if ($ifFqcn !== null && $elseFqcn !== null) {
                    $this->emitResolved($call, $rule, $ifFqcn, $ifBranch);
                    $this->emitResolved($call, $rule, $elseFqcn, $first->else);

                    return;
                }
            }
        }

        if ($resolved !== null) {
            $this->emitResolved($call, $rule, $resolved, $first);

            return;
        }

        // The outer PendingDispatch chain is only on the stack now; keep it for the deferred emit
        $outerLinks = $this->outerChainLinks($call);
        if ($this->deferVariable($call, $rule, $first, fn (Node\Expr $value): bool => $this->emitTargets($value, fn (string $target, Node\Expr $node) => $this->emitResolved($call, $rule, $target, $node, $outerLinks)))) {
            return;
        }

        $this->recordUnresolved($call, $first, $rule);
    }

    /**
     * Bus::chain([...]) / Bus::batch([...]) / Queue::bulk([...]): one job site
     * per literal item. A nested array literal is a chain inside a batch and is
     * read the same way; an argument that is not an array literal, or a spread
     * item, is an unresolved `dynamic_list`, any other unreadable item an
     * unresolved dispatch for that item.
     */
    private function recordJobList(CallSite $call, DispatchRule $rule): void
    {
        $first = $call->args()->valueAt(0);
        if ($first === null) {
            return;
        }

        if (! $first instanceof Node\Expr\Array_) {
            // `$jobs = [new A, new B]; Bus::chain($jobs)`: the list literal assigned to the variable
            $traced = $this->deferVariable($call, $rule, $first, function (Node\Expr $value) use ($call, $rule): bool {
                if (! $value instanceof Node\Expr\Array_) {
                    return false;
                }
                $this->recordJobItems($call, $rule, $value);

                return true;
            }, UnresolvedReason::DYNAMIC_LIST);
            if (! $traced) {
                $this->recordUnresolvedWithReason($call, $rule, UnresolvedReason::DYNAMIC_LIST);
            }

            return;
        }

        $this->recordJobItems($call, $rule, $first);
    }

    private function recordJobItems(CallSite $call, DispatchRule $rule, Node\Expr\Array_ $list): void
    {
        foreach ($list->items as $item) {
            $value = $item->value;

            // `...$jobs`: items only known at runtime
            if ($item->unpack) {
                $this->recordUnresolvedWithReason($call, $rule, UnresolvedReason::DYNAMIC_LIST);

                continue;
            }

            // `[new A, new B]` inside a batch: a chain, read item by item
            if ($value instanceof Node\Expr\Array_) {
                $this->recordJobItems($call, $rule, $value);

                continue;
            }

            $resolved = $this->classRef($value) ?? $this->classString($rule, $value);
            if ($resolved !== null) {
                $this->emitResolved($call, $rule, $resolved, $value);

                continue;
            }

            $this->recordUnresolved($call, $value, $rule);
        }
    }

    private function recordUnresolved(CallSite $call, Node\Expr $value, DispatchRule $rule): void
    {
        $this->recordParamDispatch($call, $value, $rule);
        $this->recordUnresolvedWithReason($call, $rule, $this->classifyUnresolvedReason($value, $rule->kind));
    }

    private function recordUnresolvedWithReason(CallSite $call, DispatchRule $rule, UnresolvedReason $reason): void
    {
        $this->recordUnresolvedAt($call, $rule->label(), $reason);
    }

    private function recordUnresolvedAt(CallSite $call, string $label, UnresolvedReason $reason): void
    {
        if ($this->shouldSkipUnresolved()) {
            return;
        }

        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $call->line(),
            expression: $this->renderExpression($call->node(), $label),
            reason: $reason->value,
        );
    }

    /** `new X` / `X::class` as an FQCN, with `self` / `static` / `parent` resolved against the enclosing class. */
    private function classRef(?Node $expr): ?string
    {
        return $this->resolveClassName(ClassRef::fromInstanceOrConstant($expr));
    }

    /**
     * @param  list<CallSite>|null  $outerLinks  the PendingDispatch chain, when it was captured before the call left the stack
     */
    private function emitResolved(CallSite $call, DispatchRule $rule, string $targetFqcn, Node\Expr $argValue, ?array $outerLinks = null): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        // Argument-instance chain, then the outer PendingDispatch chain wrapping
        // `dispatch(...)` / `event(...)`. Inner links first so an outer
        // modifier wins on a same-key conflict.
        $innerLinks = CallChain::methodLinks($argValue);
        $outerLinks ??= $this->outerChainLinks($call);

        $this->sites[] = new DispatchSiteRecord(
            classFqcn: $this->currentClassFqcn(),
            method: $this->currentMethod(),
            target: $targetFqcn,
            form: $rule->form,
            provisionalKind: $rule->kind,
            file: null,
            line: $call->line(),
            confidence: Confidence::HIGH->value,
            overrides: $this->overridesFrom($innerLinks, $outerLinks),
            // `->afterResponse()` exists only on PendingDispatch, never on event().
            mode: $rule->mode ?? ($rule->kind === DispatchKinds::EVENT ? null : ChainModifierExtractor::mode($outerLinks)),
            inClosure: $this->inClosure(),
            expression: $this->renderExpression($call->node(), $rule->label()),
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

    /** The reason an unreadable dispatch target stays unresolved, from the shape of its expression. */
    private function classifyUnresolvedReason(Node $expr, DispatchKinds $kind): UnresolvedReason
    {
        return match (true) {
            // `new self` / `self::class` in a trait or anonymous class
            $this->isUnnameableRelativeClass($expr) => UnresolvedReason::RELATIVE_CLASS_NAME,
            // `event($event)`
            $expr instanceof Node\Expr\Variable => UnresolvedReason::DYNAMIC_CLASS_NAME,
            // `new $class`
            $expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Expr => UnresolvedReason::DYNAMIC_CLASS_NAME,
            // `event(app(Foo::class))`, `resolve(...)`
            $expr instanceof Node\Expr\FuncCall
                && $expr->name instanceof Node\Name
                && in_array(strtolower($expr->name->toString()), ['app', 'resolve'], true) => UnresolvedReason::CONTAINER_RESOLUTION,
            // `$container->make(Foo::class)`
            $expr instanceof Node\Expr\MethodCall
                && $expr->name instanceof Node\Identifier
                && $expr->name->toString() === 'make' => UnresolvedReason::CONTAINER_RESOLUTION,
            // `'App\\Events\\'.$name`, `"App\\Events\\{$name}"`
            $expr instanceof Node\Expr\BinaryOp\Concat, $expr instanceof Node\Scalar\InterpolatedString => UnresolvedReason::STRING_CONCATENATION,
            // `$flag ? new A : $other`, `match (...) {...}`
            $expr instanceof Node\Expr\Ternary, $expr instanceof Node\Expr\Match_ => UnresolvedReason::CONDITIONAL_DISPATCH,
            // `event('service.installed')`
            $expr instanceof Node\Scalar\String_ && $kind === DispatchKinds::EVENT => UnresolvedReason::STRING_EVENT_NAME,
            // `dispatch(function () {...})`, `dispatch(fn () => ...)`
            ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) && $kind === DispatchKinds::JOB => UnresolvedReason::CLOSURE_JOB,
            // `Mail::send('emails.welcome', $data, fn ($m) => ...)`, `Mail::send([], [], fn ...)`
            ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Expr\Array_) && $kind === DispatchKinds::MAILABLE => UnresolvedReason::VIEW_MAIL,
            // `event($this->makeEvent())`, `dispatch(JobFactory::build())`, `event($make())`
            $expr instanceof Node\Expr\MethodCall,
            $expr instanceof Node\Expr\NullsafeMethodCall,
            $expr instanceof Node\Expr\StaticCall,
            $expr instanceof Node\Expr\FuncCall => UnresolvedReason::CALL_RESULT,
            // `event($this->event)`, `dispatch($jobs[0])`, `event(self::EVENT)`, `dispatch(static::$job)`
            $expr instanceof Node\Expr\PropertyFetch,
            $expr instanceof Node\Expr\NullsafePropertyFetch,
            $expr instanceof Node\Expr\StaticPropertyFetch,
            $expr instanceof Node\Expr\ArrayDimFetch,
            $expr instanceof Node\Expr\ClassConstFetch => UnresolvedReason::STORED_TARGET,
            default => UnresolvedReason::UNSUPPORTED_EXPRESSION,
        };
    }

    /** `new self` / `self::class` (also `static`, `parent`) where the enclosing code cannot name that class. */
    private function isUnnameableRelativeClass(Node $expr): bool
    {
        $name = match (true) {
            $expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name => $expr->class,
            $expr instanceof Node\Expr\ClassConstFetch
                && $expr->class instanceof Node\Name
                && $expr->name instanceof Node\Identifier
                && $expr->name->toString() === 'class' => $expr->class,
            default => null,
        };

        return $name !== null
            && RelativeClassName::fromName($name->toString()) !== null
            && $this->resolveClassName($name->toString()) === null;
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
     * The outer PendingDispatch chain wrapping a dispatch call, e.g.
     * `Job::dispatch($o)->onQueue('q')->delay(60)`. The modifier MethodCalls
     * are ancestors of the call and are still on $this->methodCallStack while
     * it resolves; those whose receiver chain reaches the call are its links.
     *
     * @return list<CallSite>
     */
    private function outerChainLinks(CallSite $dispatch): array
    {
        $links = [];
        foreach ($this->methodCallStack as $candidate) {
            if ($candidate->receiverReaches($dispatch->node())) {
                $links[] = $candidate;
            }
        }

        // Stack is parent-(outermost-)first; reverse to source order so the
        // link nearest the dispatch call is applied first.
        return array_reverse($links);
    }

    /**
     * Combine link lists (in the given order) into one DispatchOverrides.
     * Later links win per key; passing inner links before outer links makes
     * an outer modifier take precedence over a same-key inner one.
     *
     * @param  list<CallSite>  ...$linkLists
     */
    private function overridesFrom(array ...$linkLists): DispatchOverrides
    {
        return ChainModifierExtractor::extract(array_values(Arr::collapse($linkLists)));
    }

    /** @return list<MethodCallEdge> */
    public function getCallEdges(): array
    {
        return array_values($this->callEdges);
    }

    /** @return list<ParamDispatchRecord> */
    public function getParamDispatches(): array
    {
        return $this->paramDispatches;
    }

    /** @return list<ClassArgumentCall> */
    public function getClassArgumentCalls(): array
    {
        return $this->classArgumentCalls;
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
