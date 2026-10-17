<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ChainLink;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Scanners\Schedule\ScheduleGuard;
use Lucasp\Loom\Support\Ast\Arg;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Captures Laravel task-scheduler chains (variable-rooted and facade-rooted).
 * One chain per outermost call whose root is `call`, `command`, `job` or
 * `exec`. In facade mode the receiver is the `Schedule` facade. In kernel mode
 * it is a variable inside a `schedule()` method, and in bootstrap mode a
 * variable inside a closure passed to `withSchedule()`. A variable typed
 * `Illuminate\Console\Scheduling\Schedule` is a receiver in every mode, and
 * so is a `$this->property` that holds one. `Artisan::command(...)->freq()`
 * is a chain too: the closure command forwards to the scheduler. Group frames
 * are pushed on enter and popped on leave so inner tasks see them. Enclosing
 * `if` branches are recorded on the entry as guards.
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
     * @var list<list<ChainLink>>
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

    private const SCHEDULE_TYPE = 'Illuminate\\Console\\Scheduling\\Schedule';

    /** `ClosureCommand` methods that configure the command and do not schedule it. */
    private const COMMAND_CONFIG_METHODS = ['purpose', 'describe'];

    /**
     * Names of Schedule-typed parameters, one frame per enclosing function.
     *
     * @var list<list<string>>
     */
    private array $scheduleVariableFrames = [];

    /**
     * Property names that hold a Schedule: typed properties, promoted
     * constructor parameters and `$this->x = $schedule` assignments.
     *
     * @var array<string, true>
     */
    private array $scheduleProperties = [];

    /** Line of the first schedule scope with statements; null when none. */
    private ?int $bodyScopeLine = null;

    private ?Standard $printer = null;

    /**
     * Keys of `schedule()` methods (kernel mode).
     *
     * @var array<string, true>
     */
    private array $scheduleRoots = [];

    /**
     * Same-class `$this->method()` calls, by calling method key.
     *
     * @var array<string, list<string>>
     */
    private array $calls = [];

    /**
     * Kernel-mode entries inside a method, held until reachability from
     * `schedule()` is known.
     *
     * @var list<array{entry: ScheduleChainEntry, method: string}>
     */
    private array $pending = [];

    /** Line of the first chain in a Schedule helper that `schedule()` never calls. */
    private ?int $unreachedLine = null;

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
        $this->scheduleVariableFrames = [];
        $this->scheduleProperties = [];
        $this->bodyScopeLine = null;
        $this->calls = [];
        $this->scheduleRoots = [];
        $this->pending = [];
        $this->unreachedLine = null;
        $this->entries = [];
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        $this->trackScheduleBindings($node);

        if ($this->mode === ScheduleMode::KERNEL && $this->isScheduleMethod($node)) {
            $this->scheduleRoots[(string) $this->currentMethodKey()] = true;
        }

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

        if ($this->isFunctionLike($node)) {
            array_pop($this->scheduleVariableFrames);
        }

        // Pop the group frame this node opened (if any) before any early return,
        // so frames stay balanced regardless of node type.
        if ($this->groupOpenerStack !== [] && end($this->groupOpenerStack) === $node) {
            array_pop($this->groupOpenerStack);
            array_pop($this->groupFrameStack);
        }

        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        $this->recordSameClassCall($node);

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
        if ($receiver === null) {
            return null;
        }

        $closureCommand = $this->isClosureCommandRoot($rootMethod, $receiver);
        if (! $closureCommand && ! $this->admitsReceiver($receiver)) {
            return null;
        }
        if ($closureCommand && ! $this->inTrustedScope()) {
            return null;
        }
        // `Artisan::command(...)->purpose(...)` on its own registers nothing.
        if ($closureCommand && ! $this->schedulesClosureCommand($callChain->links())) {
            return null;
        }

        $kind = $this->kindFromRootMethod($rootMethod);

        $chain = [];
        foreach ($callChain->links() as $link) {
            $chain[] = ChainLink::fromSite($link);
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

        $entry = new ScheduleChainEntry(
            kind: $kind,
            rootMethod: $rootMethod,
            rootArgs: $root->args(),
            chain: $chain,
            line: $root->line(),
            guards: $this->enclosingGuards($node),
            closureCommand: $closureCommand,
        );

        // Inside a kernel method: kept only when `schedule()` reaches it, decided after the walk.
        $method = $this->mode === ScheduleMode::KERNEL ? $this->currentMethodKey() : null;
        if ($method === null) {
            $this->entries[] = $entry;

            return null;
        }
        $this->pending[] = ['entry' => $entry, 'method' => $method];

        return null;
    }

    /**
     * Keep the pending entries whose method is `schedule()` or reachable from
     * it through `$this->method()` calls; note the first that is not.
     *
     * @param  array<int, Node>  $nodes
     */
    public function afterTraverse(array $nodes): null
    {
        $reachable = [];
        foreach ($this->pending as $item) {
            $reachable[$item['method']] ??= $this->isReachable($item['method']);
        }

        foreach ($this->pending as $item) {
            if ($reachable[$item['method']]) {
                $this->entries[] = $item['entry'];

                continue;
            }
            $this->unreachedLine ??= $item['entry']->line;
        }
        $this->pending = [];

        return null;
    }

    private function isReachable(string $method): bool
    {
        $seen = [];
        $queue = array_keys($this->scheduleRoots);

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $method) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($this->calls[$current] ?? [] as $callee) {
                $queue[] = $callee;
            }
        }

        return false;
    }

    /** `<class id>::<method>` of the method the walk is inside, or null outside one. */
    private function currentMethodKey(): ?string
    {
        $stack = $this->parentStack;
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $node = $stack[$i];
            if (! $node instanceof Node\Stmt\ClassMethod || ! $stack[$i - 1] instanceof Node\Stmt\ClassLike) {
                continue;
            }

            return spl_object_id($stack[$i - 1]).'::'.Str::lower($node->name->toString());
        }

        return null;
    }

    /** `$this->helper(...)` or `self::helper(...)` inside a method: an edge of the in-class call graph. */
    private function recordSameClassCall(Node $node): void
    {
        $caller = $this->currentMethodKey();
        if ($caller === null) {
            return;
        }

        $name = null;
        if ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
            $name = $node->name;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && in_array($node->class->toString(), ['self', 'static'], true)) {
            $name = $node->name;
        }
        if (! $name instanceof Node\Identifier) {
            return;
        }

        $this->calls[$caller][] = Str::before($caller, '::').'::'.Str::lower($name->toString());
    }

    public function unreachedHelperLine(): ?int
    {
        return $this->unreachedLine;
    }

    /**
     * Whether a chain root's receiver is a Schedule: a Schedule-typed variable
     * or property in any mode, a plain variable or the facade inside a
     * trusted scope.
     */
    private function admitsReceiver(Node $receiver): bool
    {
        if ($this->isProvenSchedule($receiver)) {
            return true;
        }

        // Facade mode ignores unproven variables; kernel/bootstrap modes gate on the trusted scope.
        if ($receiver instanceof Node\Expr\Variable) {
            return $this->mode !== ScheduleMode::FACADE && $this->inTrustedScope();
        }

        // NameResolver has already rewritten the name to its FQCN.
        if ($receiver instanceof Node\Name) {
            return Facades::SCHEDULE->matches($receiver->toString()) && $this->inTrustedScope();
        }

        return false;
    }

    /** A variable declared as a Schedule parameter, or a property known to hold one. */
    private function isProvenSchedule(Node $receiver): bool
    {
        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            foreach ($this->scheduleVariableFrames as $frame) {
                if (in_array($receiver->name, $frame, true)) {
                    return true;
                }
            }

            return false;
        }

        $property = $this->thisPropertyName($receiver);

        return $property !== null && isset($this->scheduleProperties[$property]);
    }

    private function thisPropertyName(Node $node): ?string
    {
        if (! $node instanceof Node\Expr\PropertyFetch) {
            return null;
        }
        if (! $node->var instanceof Node\Expr\Variable || $node->var->name !== 'this') {
            return null;
        }

        return $node->name instanceof Node\Identifier ? $node->name->toString() : null;
    }

    private function isClosureCommandRoot(string $rootMethod, Node $receiver): bool
    {
        return $rootMethod === 'command'
            && $receiver instanceof Node\Name
            && Facades::ARTISAN->matches($receiver->toString());
    }

    /**
     * Any call after the root other than the command's own configuration
     * forwards to a new scheduled event.
     *
     * @param  non-empty-list<CallSite>  $links
     */
    private function schedulesClosureCommand(array $links): bool
    {
        foreach (array_slice($links, 1) as $link) {
            if (! in_array((string) $link->name(), self::COMMAND_CONFIG_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    private function isFunctionLike(Node $node): bool
    {
        return $node instanceof Node\FunctionLike;
    }

    private function isScheduleType(?Node $type): bool
    {
        if (! $type instanceof Node\Name) {
            return false;
        }

        return $type->toString() === self::SCHEDULE_TYPE || Str::endsWith($type->toString(), 'Schedule');
    }

    /**
     * Open a parameter frame for function-likes and record which properties
     * hold a Schedule. Called on every entered node.
     */
    private function trackScheduleBindings(Node $node): void
    {
        // Facade mode reads the whole app; a typed variable proves nothing there.
        if ($this->mode === ScheduleMode::FACADE) {
            return;
        }

        // A typed property: `private Schedule $schedule;`
        if ($node instanceof Node\Stmt\Property && $this->isScheduleType($node->type)) {
            foreach ($node->props as $prop) {
                $this->scheduleProperties[$prop->name->toString()] = true;
            }

            return;
        }

        // `$this->schedule = $schedule;` with a Schedule-typed variable on the right
        if ($node instanceof Node\Expr\Assign) {
            $property = $this->thisPropertyName($node->var);
            if ($property !== null && $node->expr instanceof Node\Expr\Variable && $this->isProvenSchedule($node->expr)) {
                $this->scheduleProperties[$property] = true;
            }

            return;
        }

        if (! $node instanceof Node\FunctionLike) {
            return;
        }

        $names = [];
        foreach ($node->getParams() as $param) {
            if (! $this->isScheduleType($param->type) || ! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }
            $names[] = $param->var->name;
            // A promoted constructor parameter is a property too.
            if ($param->flags !== 0) {
                $this->scheduleProperties[$param->var->name] = true;
            }
        }
        $this->scheduleVariableFrames[] = $names;

        $this->noteBodyScope($node);
    }

    /** Remember the first schedule scope that has statements, to report when it yields nothing. */
    private function noteBodyScope(Node $node): void
    {
        if ($this->bodyScopeLine !== null) {
            return;
        }

        $index = count($this->parentStack) - 1;
        $isScope = ($this->mode === ScheduleMode::KERNEL && $this->isScheduleMethod($node))
            || ($this->mode === ScheduleMode::BOOTSTRAP && $this->closureIsWithSchedule($index));
        $hasBody = ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure) && ($node->stmts ?? []) !== [];
        if ($isScope && $hasBody) {
            $this->bodyScopeLine = $node->getStartLine();
        }
    }

    /**
     * The `if` branches that enclose the call, outermost first, as constraint
     * strings. The parent stack holds only ancestors when this runs.
     *
     * @return list<string>
     */
    private function enclosingGuards(Node $node): array
    {
        $guards = [];
        $ancestors = $this->parentStack;

        foreach ($ancestors as $i => $ancestor) {
            $child = $ancestors[$i + 1] ?? $node;
            $guard = $this->guardFor($ancestor, $child, $ancestors[$i - 1] ?? null);
            if ($guard !== null) {
                $guards[] = $guard;
            }
        }

        return $guards;
    }

    private function guardFor(Node $ancestor, Node $child, ?Node $parent): ?string
    {
        // `if (c) { <here> }`
        if ($ancestor instanceof Node\Stmt\If_ && in_array($child, $ancestor->stmts, true)) {
            return ScheduleGuard::THEN->render($this->condition($ancestor->cond));
        }

        // `elseif (c) { <here> }`
        if ($ancestor instanceof Node\Stmt\ElseIf_ && in_array($child, $ancestor->stmts, true)) {
            return ScheduleGuard::OTHERWISE_IF->render($this->condition($ancestor->cond));
        }

        // `else { <here> }`: shown with the condition of the `if` it belongs to
        if ($ancestor instanceof Node\Stmt\Else_ && $parent instanceof Node\Stmt\If_ && in_array($child, $ancestor->stmts, true)) {
            return ScheduleGuard::OTHERWISE->render($this->condition($parent->cond));
        }

        return null;
    }

    private function condition(Node\Expr $condition): string
    {
        $this->printer ??= new Standard;

        return Str::squish($this->printer->prettyPrintExpr($condition));
    }

    public function bodyScopeLine(): ?int
    {
        return $this->bodyScopeLine;
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
            if ($this->closureIsWithSchedule($i)) {
                return true;
            }
        }

        return false;
    }

    /** True when the stack node at $index is a closure passed as an argument to `withSchedule()`. */
    private function closureIsWithSchedule(int $index): bool
    {
        if ($index < 2) {
            return false;
        }
        $node = $this->parentStack[$index];
        if (! $node instanceof Node\Expr\Closure && ! $node instanceof Node\Expr\ArrowFunction) {
            return false;
        }
        if (! Arg::isNode($this->parentStack[$index - 1])) {
            return false;
        }
        $grand = $this->parentStack[$index - 2];
        if (! $grand instanceof Node\Expr\MethodCall && ! $grand instanceof Node\Expr\StaticCall) {
            return false;
        }

        return $grand->name instanceof Node\Identifier && $grand->name->toString() === 'withSchedule';
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
     * @return list<ChainLink>|null
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
        if ($receiver === null || ! $this->admitsReceiver($receiver)) {
            return null;
        }

        // Drop the terminal `group` link; the rest are the inherited modifiers.
        $frame = [];
        foreach ($callChain->withoutLast() as $link) {
            $frame[] = ChainLink::fromSite($link);
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
