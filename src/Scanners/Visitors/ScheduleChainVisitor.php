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
    public const MODE_KERNEL = 'kernel';

    public const MODE_BOOTSTRAP = 'bootstrap';

    public const MODE_FACADE = 'facade';

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
     * Discovery mode. Selects the trusted-scope shape:
     *
     * - `MODE_KERNEL`: only emit chains inside a `schedule(Schedule $schedule)`
     *   method body — narrows file-level walks of `app/Console/Kernel.php`
     *   so unrelated builder DSLs in helper methods don't leak in.
     * - `MODE_BOOTSTRAP`: only emit chains inside a closure passed to
     *   `withSchedule(...)` — narrows file-level walks of `bootstrap/app.php`.
     * - `MODE_FACADE`: only emit chains whose root receiver is the
     *   `Schedule` facade. Variable-rooted chains are ignored. Used by the
     *   `app/`-wide facade pass to avoid false positives on arbitrary
     *   builder DSLs.
     */
    private string $mode;

    public function __construct(string $mode = self::MODE_KERNEL)
    {
        $this->mode = $mode;
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

        // Trusted-scope check: kernel/bootstrap modes only emit chains
        // inside the relevant scope (the schedule() method body or the
        // withSchedule() closure body). Facade mode trusts the receiver
        // shape and emits anywhere.
        if (! $this->inTrustedScope()) {
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
     *     method whose `$schedule` parameter we trust by convention,
     *     gated further by the trusted-scope check), or
     *   - A Name resolving to the Schedule facade.
     */
    private function isScheduleReceiver(Node $receiver): bool
    {
        if ($receiver instanceof Node\Expr\Variable) {
            // In facade mode the only trust anchor is the receiver itself;
            // variable-rooted chains are ignored. Kernel/bootstrap modes
            // accept variables but the trusted-scope check still gates
            // emission.
            return $this->mode !== self::MODE_FACADE;
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
     * For kernel/bootstrap mode, the parent stack must contain the relevant
     * ancestor scope (a `schedule(Schedule $schedule)` method body, or a
     * closure passed as the first arg to a `withSchedule(...)` call).
     * Facade mode trusts the receiver shape and skips this check.
     */
    private function inTrustedScope(): bool
    {
        if ($this->mode === self::MODE_FACADE) {
            return true;
        }

        if ($this->mode === self::MODE_KERNEL) {
            foreach ($this->parentStack as $ancestor) {
                if ($this->isScheduleMethod($ancestor)) {
                    return true;
                }
            }

            return false;
        }

        // MODE_BOOTSTRAP — look for a closure/arrow-function whose immediate
        // parent on the stack is an Arg whose parent is a withSchedule call.
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
     * Match `function schedule(Schedule $schedule)` — method named `schedule`
     * whose first parameter's type is `Schedule` (or any FQ name ending in
     * `Schedule`). Loose on the leading namespace so both the imported
     * (`use Illuminate\Console\Scheduling\Schedule`) and fully-qualified
     * forms match.
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
