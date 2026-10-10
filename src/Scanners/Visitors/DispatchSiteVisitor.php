<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Arr;
use Lucasp\Loom\Dto\DispatchOverrides;
use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Scanners\Dispatch\DispatchRule;
use Lucasp\Loom\Scanners\Dispatch\DispatchRuleMatcher;
use Lucasp\Loom\Scanners\Dispatch\DispatchTarget;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\ChainModifierExtractor;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Collects statically resolvable dispatch sites in a parsed file.
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

    /** @var list<DispatchSiteRecord> */
    private array $sites = [];

    /** @var list<UnresolvedDispatchRecord> */
    private array $unresolved = [];

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
        $this->sites = [];
        $this->unresolved = [];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $site = CallSite::of($node);
            if ($site !== null) {
                $this->methodCallStack[] = $site;
            }
        }

        $this->enterClassScope($node);

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->closureDepth++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $call = CallSite::of($node);
        if ($call !== null) {
            $rule = $this->matcher->match($call);
            if ($rule !== null) {
                $this->record($call, $rule);
            }
        }

        if ($node instanceof Node\Expr\MethodCall) {
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
        };
    }

    private function recordDispatchable(CallSite $call, DispatchRule $rule): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        $className = $call->className();
        if ($className === null) {
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
        $args = $call->args();
        $argValue = $args->valueAt($rule->argIndex);
        if ($argValue === null) {
            return;
        }

        $resolved = ClassRef::fromInstanceOrConstant($argValue);

        if ($resolved !== null) {
            if ($this->shouldSkipResolved()) {
                return;
            }

            // Argument-instance chain + Mail/Notification receiver chain
            // (`Mail::to(...)->locale(...)->send(...)`); a static call has no
            // receiver links.
            $innerLinks = CallChain::methodLinks($argValue);
            $receiverLinks = $call->receiverLinks();

            $this->sites[] = new DispatchSiteRecord(
                classFqcn: $this->currentClassFqcn(),
                method: $this->currentMethod(),
                target: $resolved,
                form: $rule->form,
                provisionalKind: $rule->kind,
                file: null,
                line: $call->line(),
                confidence: Confidence::HIGH->value,
                overrides: $this->overridesFrom($innerLinks, $receiverLinks),
                mode: $rule->mode,
                // Only the facade form takes a channel filter argument.
                channels: $rule->channelsAt !== null ? $this->channelFilterFrom($args, $rule->channelsAt) : null,
                inClosure: $this->inClosure(),
            );

            return;
        }

        $this->recordUnresolved($call, $argValue, $rule);
    }

    private function recordHelperOrFacade(CallSite $call, DispatchRule $rule): void
    {
        $first = $call->args()->valueAt($rule->argIndex);
        if ($first === null) {
            return;
        }

        $resolved = ClassRef::fromInstanceOrConstant($first);

        // Ternary with two statically resolvable branches: emit both.
        if ($resolved === null && $first instanceof Node\Expr\Ternary) {
            $ifBranch = $first->if;

            if ($ifBranch !== null) {
                $ifFqcn = ClassRef::fromInstanceOrConstant($ifBranch);
                $elseFqcn = ClassRef::fromInstanceOrConstant($first->else);

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

        $this->recordUnresolved($call, $first, $rule);
    }

    /**
     * Bus::chain([...]) / Bus::batch([...]): one job site per literal item; a
     * non-literal list or item is recorded as unresolved.
     */
    private function recordJobList(CallSite $call, DispatchRule $rule): void
    {
        $first = $call->args()->valueAt(0);
        if ($first === null) {
            return;
        }

        if (! $first instanceof Node\Expr\Array_) {
            $this->recordUnresolved($call, $first, $rule);

            return;
        }

        foreach ($first->items as $item) {
            $value = $item->value;
            $resolved = ClassRef::fromInstanceOrConstant($value);
            if ($resolved !== null && ! $item->unpack) {
                $this->emitResolved($call, $rule, $resolved, $value);

                continue;
            }

            $this->recordUnresolved($call, $value, $rule);
        }
    }

    private function recordUnresolved(CallSite $call, Node\Expr $value, DispatchRule $rule): void
    {
        if ($this->shouldSkipUnresolved()) {
            return;
        }

        $this->unresolved[] = new UnresolvedDispatchRecord(
            file: null,
            line: $call->line(),
            expression: $this->renderExpression($call->node(), $rule->label()),
            reason: $this->classifyUnresolvedReason($value),
        );
    }

    private function emitResolved(CallSite $call, DispatchRule $rule, string $targetFqcn, Node\Expr $argValue): void
    {
        if ($this->shouldSkipResolved()) {
            return;
        }

        // Argument-instance chain, then the outer PendingDispatch chain wrapping
        // `dispatch(...)` / `event(...)`. Inner links first so an outer
        // modifier wins on a same-key conflict.
        $innerLinks = CallChain::methodLinks($argValue);
        $outerLinks = $this->outerChainLinks($call);

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
