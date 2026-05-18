<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Captures Laravel task-scheduler chains.
 *
 * A chain is recognised in three shapes (the visitor handles all three):
 *
 *   1. Variable-rooted:   `$schedule->command(...)->daily()`
 *   2. Facade-rooted:     `Schedule::command(...)->daily()` where Schedule
 *      resolves to `Illuminate\Support\Facades\Schedule`.
 *   3. Aliased facade:    bare `Schedule::command(...)` with the Schedule
 *      facade imported (NameResolver attaches the FQCN).
 *
 * Detection happens on `leaveNode` for outermost `Expr\MethodCall` /
 * `Expr\StaticCall` nodes — by walking down `->var` to find the chain root
 * and skipping nodes whose parent is also part of the same chain we emit
 * exactly one entry per chain.
 *
 * See docs/scanners/schedule.md and docs/adr/0002-schedule-scanner.md.
 */
final class ScheduleChainVisitor extends NodeVisitorAbstract
{
    private const SCHEDULE_FACADE = 'Illuminate\\Support\\Facades\\Schedule';

    /** @var array<int, string> */
    private const ROOT_METHODS = ['command', 'job', 'call', 'exec'];

    /**
     * Stack of parent nodes for the node currently being processed in
     * `leaveNode`. Used to detect whether the current MethodCall is the
     * outermost call in its chain.
     *
     * @var array<int, Node>
     */
    private array $parentStack = [];

    /**
     * When true, only chains rooted at the `Schedule` facade are emitted.
     * Variable-rooted chains (`$x->command(...)->daily()`) are ignored.
     *
     * Used by ScheduleScanner's facade-form discovery pass, which walks
     * every file under `app/` and must not pick up arbitrary builders or
     * DSLs that happen to share the `command`/`job`/`call`/`exec` + frequency
     * helper shape. The kernel and bootstrap discovery passes leave this
     * false so they can match the `$schedule->...` parameter convention.
     */
    private bool $requireFacadeRoot;

    public function __construct(bool $requireFacadeRoot = false)
    {
        $this->requireFacadeRoot = $requireFacadeRoot;
    }

    /**
     * @var array<int, array{
     *     kind: 'command'|'job'|'closure'|'exec',
     *     root_method: string,
     *     root_args: array<int, Node\Arg|Node\VariadicPlaceholder>,
     *     chain: list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>}>,
     *     line: int
     * }>
     */
    private array $entries = [];

    /**
     * Set to true while traversing inside a chain we've already emitted from
     * the outermost call. We don't actually skip subtree traversal (parent
     * tracking handles emission), but parent tracking does the work.
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->parentStack = [];
        $this->entries = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->parentStack);

        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        // Only emit at the outermost call. If the parent is a MethodCall
        // whose `->var` is the current node, we're inside a longer chain.
        $parent = $this->currentParent();
        if ($parent instanceof Node\Expr\MethodCall && $parent->var === $node) {
            return null;
        }

        $links = $this->collectChain($node);
        if ($links === null) {
            return null;
        }

        // The root link is the first (deepest) link. It must be a recognised
        // root method and a recognised receiver.
        $root = $links[0];
        if (! in_array($root['method'], self::ROOT_METHODS, true)) {
            return null;
        }
        if (! $this->isScheduleReceiver($root['receiver'])) {
            return null;
        }

        $kind = $this->kindFromRootMethod($root['method']);

        // Strip the receiver field for the public chain shape.
        $chain = [];
        foreach ($links as $link) {
            $chain[] = ['method' => $link['method'], 'args' => $link['args']];
        }

        $this->entries[] = [
            'kind' => $kind,
            'root_method' => $root['method'],
            'root_args' => $root['args'],
            'chain' => $chain,
            'line' => $root['line'],
        ];

        return null;
    }

    /**
     * Walk down `->var` (for MethodCall) collecting `(method, args, receiver)`
     * for each link. Returns null if the chain is malformed (e.g. a Name
     * call that isn't a method/static call on a recognised root).
     *
     * The returned list is ordered root-first (deepest call → outermost call).
     *
     * @return list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>, receiver: Node\Expr|Node\Name, line: int}>|null
     */
    private function collectChain(Node\Expr $outer): ?array
    {
        $links = [];
        $current = $outer;

        // Walk outermost → innermost, prepending each link so the final list
        // is root-first.
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

            // Reached a non-call receiver (Variable, etc.) — done walking.
            break;
        }

        return $links === [] ? null : $links;
    }

    /**
     * A chain root receiver is "schedule-shaped" when it is either:
     *   - A Variable (any name — chains live inside a closure or kernel
     *     method whose `$schedule` parameter we trust by convention), or
     *   - A Name resolving to the Schedule facade.
     */
    private function isScheduleReceiver(Node $receiver): bool
    {
        if ($receiver instanceof Node\Expr\Variable) {
            return ! $this->requireFacadeRoot;
        }

        if ($receiver instanceof Node\Name) {
            $resolved = $receiver->getAttribute('resolvedName');
            if ($resolved instanceof Node\Name) {
                return $resolved->toString() === self::SCHEDULE_FACADE;
            }

            // Fallback — should not happen post-NameResolver, but if a file
            // is parsed outside a namespace and the name is already FQ, match
            // directly.
            return $receiver->toString() === self::SCHEDULE_FACADE
                || $receiver->toString() === 'Schedule';
        }

        return false;
    }

    /**
     * @return 'command'|'job'|'closure'|'exec'
     */
    private function kindFromRootMethod(string $method): string
    {
        return match ($method) {
            'command' => 'command',
            'job' => 'job',
            'call' => 'closure',
            'exec' => 'exec',
            default => 'closure',
        };
    }

    private function currentParent(): ?Node
    {
        if ($this->parentStack === []) {
            return null;
        }

        return $this->parentStack[count($this->parentStack) - 1];
    }

    /**
     * @return array<int, array{
     *     kind: 'command'|'job'|'closure'|'exec',
     *     root_method: string,
     *     root_args: array<int, Node\Arg|Node\VariadicPlaceholder>,
     *     chain: list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>}>,
     *     line: int
     * }>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
