<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduleChainLink;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Support\Ast\Arg;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;

/**
 * Captures Laravel task-scheduler chains (variable-rooted and facade-rooted).
 * One chain per outermost call whose root is `call`, `command`, `job` or
 * `exec`. In facade mode the receiver is the `Schedule` facade. In kernel mode
 * it is a variable inside a `schedule()` method, and in bootstrap mode a
 * variable inside a closure passed to `withSchedule()`. Group frames are
 * pushed on enter and popped on leave so inner tasks see them.
 *
 * @internal
 */
final class ScheduleChainVisitor extends CollectingVisitor
{
    /** @var array<int, string> */
    private const ROOT_METHODS = ['command', 'job', 'call', 'exec'];

    /** @var array<int, Node> */
    private array $parentStack = [];

    /**
     * Cumulative enclosing `->group(Closure)` frames. Each frame holds the outer
     * chain's modifier links (frequency + modifiers, excluding the terminal
     * `group` link) that inner tasks inherit. Outermost frame is index 0.
     *
     * @var list<list<ScheduleChainLink>>
     */
    private array $groupFrameStack = [];

    /**
     * Group-opener nodes that pushed a frame, parallel to $groupFrameStack. Used
     * in leaveNode to pop exactly the frames this node opened (compare top===node).
     *
     * @var list<Node>
     */
    private array $groupOpenerStack = [];

    /**
     * KERNEL: emit only inside `schedule(Schedule $schedule)`.
     * BOOTSTRAP: emit only inside a `withSchedule(...)` closure.
     * FACADE: only chains rooted at the Schedule facade.
     */
    private ScheduleMode $mode;

    public function __construct(ScheduleMode $mode = ScheduleMode::KERNEL)
    {
        $this->mode = $mode;
    }

    /** @var list<ScheduleChainEntry> */
    private array $entries = [];

    protected function reset(): void
    {
        $this->parentStack = [];
        $this->groupFrameStack = [];
        $this->groupOpenerStack = [];
        $this->entries = [];
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        // PhpParser enters a group call before descending into its closure, so
        // pushing here keeps the frame visible to inner leaf tasks.
        $frame = $this->groupFrameFor($node);
        if ($frame !== null) {
            $this->groupFrameStack[] = $frame;
            $this->groupOpenerStack[] = $node;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->parentStack);

        // Pop the group frame this node opened (if any) before any early return,
        // so frames stay balanced regardless of node type.
        if ($this->groupOpenerStack !== [] && end($this->groupOpenerStack) === $node) {
            array_pop($this->groupOpenerStack);
            array_pop($this->groupFrameStack);
        }

        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        // Emit only at the outermost call in a chain.
        $parent = $this->currentParent();
        if ($parent instanceof Node\Expr\MethodCall && $parent->var === $node) {
            return null;
        }

        $callChain = CallChain::from($node);
        if ($callChain === null) {
            return null;
        }

        $root = $callChain->root();
        $rootMethod = (string) $root->name();
        if (! in_array($rootMethod, self::ROOT_METHODS, true)) {
            return null;
        }
        $receiver = $root->receiver();
        if ($receiver === null || ! $this->isScheduleReceiver($receiver)) {
            return null;
        }

        if (! $this->inTrustedScope()) {
            return null;
        }

        $kind = $this->kindFromRootMethod($rootMethod);

        $chain = [];
        foreach ($callChain->links() as $link) {
            $chain[] = new ScheduleChainLink(method: (string) $link->name(), args: $link->args());
        }

        // Splice inherited group attributes between the inner root and the
        // inner's own modifiers, so the inner task's own modifiers come last
        // and win (Laravel: inner frequency overrides the group's).
        if ($this->groupFrameStack !== []) {
            $groupLinks = [];
            foreach ($this->groupFrameStack as $frame) {
                $groupLinks = [...$groupLinks, ...$frame];
            }
            $chain = [$chain[0], ...$groupLinks, ...array_slice($chain, 1)];
        }

        $this->entries[] = new ScheduleChainEntry(
            kind: $kind,
            rootMethod: $rootMethod,
            rootArgs: $root->args(),
            chain: $chain,
            line: $root->line(),
        );

        return null;
    }

    private function isScheduleReceiver(Node $receiver): bool
    {
        if ($receiver instanceof Node\Expr\Variable) {
            // Facade mode ignores variable receivers; kernel/bootstrap modes
            // still gate on the trusted-scope check below.
            return $this->mode !== ScheduleMode::FACADE;
        }

        if ($receiver instanceof Node\Name) {
            // NameResolver has already rewritten the name to its FQCN.
            return Facades::SCHEDULE->matches($receiver->toString());
        }

        return false;
    }

    private function inTrustedScope(): bool
    {
        if ($this->mode === ScheduleMode::FACADE) {
            return true;
        }

        if ($this->mode === ScheduleMode::KERNEL) {
            foreach ($this->parentStack as $ancestor) {
                if ($this->isScheduleMethod($ancestor)) {
                    return true;
                }
            }

            return false;
        }

        // BOOTSTRAP: closure/arrow-function passed as an Arg to withSchedule(...).
        for ($i = count($this->parentStack) - 1; $i >= 2; $i--) {
            $node = $this->parentStack[$i];
            if (! $node instanceof Node\Expr\Closure && ! $node instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            $parent = $this->parentStack[$i - 1];
            if (! Arg::isNode($parent)) {
                continue;
            }
            $grand = $this->parentStack[$i - 2];
            if (! $grand instanceof Node\Expr\MethodCall && ! $grand instanceof Node\Expr\StaticCall) {
                continue;
            }
            if (! $grand->name instanceof Node\Identifier) {
                continue;
            }
            if ($grand->name->toString() === 'withSchedule') {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches `function schedule(Schedule $schedule)` — first param type
     * must end in `Schedule` (loose so both imported and FQ forms match).
     */
    private function isScheduleMethod(Node $node): bool
    {
        if (! $node instanceof Node\Stmt\ClassMethod) {
            return false;
        }
        if ($node->name->toString() !== 'schedule') {
            return false;
        }
        if ($node->params === []) {
            return false;
        }
        $type = $node->params[0]->type;
        if (! $type instanceof Node\Name) {
            return false;
        }

        return Str::endsWith($type->toString(), 'Schedule');
    }

    private function kindFromRootMethod(string $method): ScheduleKind
    {
        return match ($method) {
            'command' => ScheduleKind::COMMAND,
            'job' => ScheduleKind::JOB,
            'call' => ScheduleKind::CLOSURE,
            'exec' => ScheduleKind::EXEC,
            default => ScheduleKind::CLOSURE,
        };
    }

    /**
     * If $node is a schedule `->group(Closure)` opener, build the inherited
     * frame from the outer chain's modifier links (all links except the
     * terminal `group` link). Otherwise null.
     *
     * The frame is purely structural — unrecognised method names (possible in
     * KERNEL/BOOTSTRAP where any in-scope receiver is trusted) are harmless:
     * the scanner's translate() silently ignores chain methods it doesn't know.
     *
     * @return list<ScheduleChainLink>|null
     */
    private function groupFrameFor(Node $node): ?array
    {
        if (! $node instanceof Node\Expr\MethodCall) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'group') {
            return null;
        }
        $args = Args::of($node->args);
        if ($args->count() !== 1) {
            return null;
        }
        $callback = $args->valueAt(0);
        if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
            return null;
        }

        $callChain = CallChain::from($node);
        if ($callChain === null) {
            return null;
        }
        $receiver = $callChain->root()->receiver();
        if ($receiver === null || ! $this->isScheduleReceiver($receiver)) {
            return null;
        }
        if (! $this->inTrustedScope()) {
            return null;
        }

        // Drop the terminal `group` link; the rest are the inherited modifiers.
        $frame = [];
        foreach ($callChain->withoutLast() as $link) {
            $frame[] = new ScheduleChainLink(method: (string) $link->name(), args: $link->args());
        }

        return $frame;
    }

    private function currentParent(): ?Node
    {
        if ($this->parentStack === []) {
            return null;
        }

        return $this->parentStack[count($this->parentStack) - 1];
    }

    /** @return list<ScheduleChainEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
