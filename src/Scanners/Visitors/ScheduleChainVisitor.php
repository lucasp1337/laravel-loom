<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduleChainLink;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Captures Laravel task-scheduler chains (variable-rooted and facade-rooted).
 */
final class ScheduleChainVisitor extends NodeVisitorAbstract
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

    public function beforeTraverse(array $nodes): ?array
    {
        $this->parentStack = [];
        $this->groupFrameStack = [];
        $this->groupOpenerStack = [];
        $this->entries = [];

        return null;
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

        $links = $this->collectChain($node);
        if ($links === null) {
            return null;
        }

        $root = $links[0];
        if (! in_array($root['method'], self::ROOT_METHODS, true)) {
            return null;
        }
        if (! $this->isScheduleReceiver($root['receiver'])) {
            return null;
        }

        if (! $this->inTrustedScope()) {
            return null;
        }

        $kind = $this->kindFromRootMethod($root['method']);

        $chain = [];
        foreach ($links as $link) {
            $chain[] = new ScheduleChainLink(method: $link['method'], args: $link['args']);
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
            rootMethod: $root['method'],
            rootArgs: $root['args'],
            chain: $chain,
            line: $root['line'],
        );

        return null;
    }

    /**
     * Returns links root-first, or null if malformed.
     *
     * @return list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>, receiver: Node\Expr|Node\Name, line: int}>|null
     */
    private function collectChain(Node\Expr $outer): ?array
    {
        $links = [];
        $current = $outer;

        while (true) {
            if ($current instanceof Node\Expr\MethodCall) {
                if (! $current->name instanceof Node\Identifier) {
                    return null;
                }
                array_unshift($links, [
                    'method' => $current->name->toString(),
                    'args' => $current->args,
                    'receiver' => $current->var,
                    'line' => $current->getStartLine(),
                ]);
                $current = $current->var;

                continue;
            }

            if ($current instanceof Node\Expr\StaticCall) {
                if (! $current->name instanceof Node\Identifier) {
                    return null;
                }
                if (! $current->class instanceof Node\Name) {
                    return null;
                }
                array_unshift($links, [
                    'method' => $current->name->toString(),
                    'args' => $current->args,
                    'receiver' => $current->class,
                    'line' => $current->getStartLine(),
                ]);
                // StaticCall is always a chain root.
                break;
            }

            // Non-call receiver (Variable, etc.) — done.
            break;
        }

        return $links === [] ? null : $links;
    }

    private function isScheduleReceiver(Node $receiver): bool
    {
        if ($receiver instanceof Node\Expr\Variable) {
            // Facade mode ignores variable receivers; kernel/bootstrap modes
            // still gate on the trusted-scope check below.
            return $this->mode !== ScheduleMode::FACADE;
        }

        if ($receiver instanceof Node\Name) {
            $resolved = $receiver->getAttribute('resolvedName');
            if ($resolved instanceof Node\Name) {
                return $resolved->toString() === Facades::SCHEDULE->value;
            }

            // Fallback when NameResolver didn't attach a resolved name.
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
            if (! $parent instanceof Node\Arg) {
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
        $resolved = $type->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $type->toString();

        return str_ends_with($name, 'Schedule');
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
        if (count($node->args) !== 1) {
            return null;
        }
        $arg = $node->args[0];
        if (! $arg instanceof Node\Arg) {
            return null;
        }
        if (! $arg->value instanceof Node\Expr\Closure && ! $arg->value instanceof Node\Expr\ArrowFunction) {
            return null;
        }

        $links = $this->collectChain($node);
        if ($links === null) {
            return null;
        }
        if (! $this->isScheduleReceiver($links[0]['receiver'])) {
            return null;
        }
        if (! $this->inTrustedScope()) {
            return null;
        }

        // Drop the terminal `group` link; the rest are the inherited modifiers.
        $frame = [];
        foreach (array_slice($links, 0, -1) as $link) {
            $frame[] = new ScheduleChainLink(method: $link['method'], args: $link['args']);
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
