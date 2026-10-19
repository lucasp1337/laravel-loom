<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ChainLink;
use Lucasp\Loom\Dto\ScheduleChainEntry;
use Lucasp\Loom\Dto\ScheduleHandoff;
use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Index\ScheduleMode;
use Lucasp\Loom\Scanners\Schedule\ScheduleGuard;
use Lucasp\Loom\Support\Ast\Arg;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Facades;
use Lucasp\Loom\Support\Fqcn;
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
 * In kernel mode with an entry point, the trusted scope is that method of that
 * class instead of `schedule()`. Calls in a trusted scope that pass the
 * Schedule to another class are kept as handoffs for the scanner to follow:
 * `X::m($schedule)`, `(new X)->m($schedule)`, `app(X::class)->m($schedule)`,
 * `resolve(X::class)` and `->make(X::class)` receivers, and a `withSchedule()`
 * callable other than a closure (`new X`, `[X::class, 'm']`, `X::m(...)`).
 * Any other receiver gives a handoff with no class.
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

    /** @var list<ScheduleHandoff> */
    private array $handoffs = [];

    /**
     * Kernel-mode handoffs inside a method, held until reachability is known.
     *
     * @var list<array{handoff: ScheduleHandoff, method: string}>
     */
    private array $pendingHandoffs = [];

    private bool $entryFound = false;

    /** @param  ScheduleHandoff|null  $entry  kernel mode: read this method of this class instead of `schedule()` */
    public function __construct(
        ScheduleMode $mode = ScheduleMode::KERNEL,
        private readonly ?ScheduleHandoff $entry = null,
    ) {
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
        $this->handoffs = [];
        $this->pendingHandoffs = [];
        $this->entryFound = false;
        $this->entries = [];
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        $this->trackScheduleBindings($node);

        if ($this->mode === ScheduleMode::KERNEL && $this->isRootMethodAt(count($this->parentStack) - 1)) {
            $this->scheduleRoots[(string) $this->currentMethodKey()] = true;
            $this->entryFound = true;
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
        $this->recordHandoff($node);

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

        foreach ($this->pendingHandoffs as $item) {
            if ($reachable[$item['method']] ??= $this->isReachable($item['method'])) {
                $this->handoffs[] = $item['handoff'];
            }
        }
        $this->pendingHandoffs = [];

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

    /** @return list<ScheduleHandoff> */
    public function getHandoffs(): array
    {
        return $this->handoffs;
    }

    /** Whether the entry point (or a `schedule()` method) was declared in the file. */
    public function foundEntry(): bool
    {
        return $this->entryFound;
    }

    /**
     * A call in a trusted scope that passes the Schedule on, or the callable
     * given to `withSchedule()`, kept as a handoff. Same-class calls are
     * edges of the call graph instead, and `parent::` calls reach the framework.
     */
    private function recordHandoff(Node\Expr\MethodCall|Node\Expr\StaticCall $node): void
    {
        if ($this->mode === ScheduleMode::FACADE) {
            return;
        }

        $handoff = $this->mode === ScheduleMode::BOOTSTRAP && $this->isWithScheduleCall($node)
            ? $this->withScheduleHandoff($node)
            : $this->passedScheduleHandoff($node);
        if ($handoff === null) {
            return;
        }

        // Inside a kernel method: kept only when `schedule()` reaches it, decided after the walk.
        $method = $this->mode === ScheduleMode::KERNEL ? $this->currentMethodKey() : null;
        if ($method !== null) {
            $this->pendingHandoffs[] = ['handoff' => $handoff, 'method' => $method];

            return;
        }
        if ($this->inTrustedScope() || $this->isWithScheduleCall($node)) {
            $this->handoffs[] = $handoff;
        }
    }

    private function isWithScheduleCall(Node\Expr\MethodCall|Node\Expr\StaticCall $node): bool
    {
        return $node->name instanceof Node\Identifier && $node->name->toString() === 'withSchedule';
    }

    /** `withSchedule(new X)`, `withSchedule([X::class, 'm'])` or `withSchedule(X::m(...))`; null for a closure. */
    private function withScheduleHandoff(Node\Expr\MethodCall|Node\Expr\StaticCall $node): ?ScheduleHandoff
    {
        $callback = Args::of($node->args)->valueAt(0);
        if ($callback === null || $callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction) {
            return null;
        }

        $line = $callback->getStartLine();
        // `new X`: an invokable object
        if ($callback instanceof Node\Expr\New_ && $callback->class instanceof Node\Name) {
            return new ScheduleHandoff($callback->class->toString(), '__invoke', 0, $line);
        }

        $target = Callables::staticTarget($callback);
        $listener = Callables::listener($callback);
        $member = match (true) {
            // `[X::class, 'm']` or `[new X, 'm']`
            $target !== null => Fqcn::splitStaticMember($target),
            // `X::m(...)`
            $listener !== null => [$listener['listener'], $listener['method']],
            // anything else cannot be read
            default => null,
        };

        return new ScheduleHandoff($member[0] ?? null, $member[1] ?? null, 0, $line);
    }

    /** A call that takes the Schedule as an argument and belongs to another class. */
    private function passedScheduleHandoff(Node\Expr\MethodCall|Node\Expr\StaticCall $node): ?ScheduleHandoff
    {
        $position = null;
        foreach (Args::of($node->args)->values() as $index => $value) {
            if ($this->isProvenSchedule($value)) {
                $position = $index;

                break;
            }
        }
        if ($position === null) {
            return null;
        }

        $class = $node instanceof Node\Expr\StaticCall
            ? $this->staticCallClass($node)
            : $this->receiverClass($node->var);
        // `$this->m()`, `self::m()`, `static::m()`, `parent::m()`: the in-class call graph or the framework
        if ($class === '') {
            return null;
        }

        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        return new ScheduleHandoff($class, $method, $position, $node->getStartLine(), $this->enclosingGuards($node));
    }

    /** The called class; '' for this class or its parent, null when unreadable. */
    private function staticCallClass(Node\Expr\StaticCall $node): ?string
    {
        if (! $node->class instanceof Node\Name) {
            return null;
        }

        $name = $node->class->toString();

        return in_array(Str::lower($name), ['self', 'static', 'parent'], true) ? '' : $name;
    }

    /** The class of a method call's receiver; '' for `$this`, null when unreadable. */
    private function receiverClass(Node\Expr $receiver): ?string
    {
        // `$this->helper($schedule)`
        if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'this') {
            return '';
        }

        // `(new X)->m($schedule)`
        if ($receiver instanceof Node\Expr\New_) {
            return ClassRef::fromInstanceOrConstant($receiver);
        }

        // `app(X::class)` / `resolve(X::class)`
        if ($receiver instanceof Node\Expr\FuncCall && $receiver->name instanceof Node\Name
            && in_array(Str::lower($receiver->name->toString()), ['app', 'resolve'], true)) {
            return ClassRef::fromClassConstant(Args::of($receiver->args)->valueAt(0));
        }

        // `$this->app->make(X::class)` / `->makeWith(X::class, [...])`
        if ($receiver instanceof Node\Expr\MethodCall && $receiver->name instanceof Node\Identifier
            && in_array($receiver->name->toString(), ['make', 'makeWith'], true)) {
            return ClassRef::fromClassConstant(Args::of($receiver->args)->valueAt(0));
        }

        return null;
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
        // The root scope's Schedule parameter, typed or not.
        $root = $this->rootScheduleParameter($node);
        if ($root !== null) {
            $names[] = $root;
        }
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

    /** The parameter a root scope receives the Schedule in: `schedule()`, the `withSchedule()` closure or the entry point. */
    private function rootScheduleParameter(Node\FunctionLike $node): ?string
    {
        $index = count($this->parentStack) - 1;
        $isRoot = ($this->mode === ScheduleMode::KERNEL && $this->isRootMethodAt($index))
            || ($this->mode === ScheduleMode::BOOTSTRAP && $this->closureIsWithSchedule($index));
        if (! $isRoot) {
            return null;
        }

        $param = $node->getParams()[$this->entry->argument ?? 0] ?? null;

        return $param !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : null;
    }

    /** Remember the first schedule scope that has statements, to report when it yields nothing. */
    private function noteBodyScope(Node $node): void
    {
        if ($this->bodyScopeLine !== null) {
            return;
        }

        $index = count($this->parentStack) - 1;
        $isScope = ($this->mode === ScheduleMode::KERNEL && $this->isRootMethodAt($index))
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
            foreach (array_keys($this->parentStack) as $index) {
                if ($this->isRootMethodAt($index)) {
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

    /** The stack node at $index is `schedule()`, or the entry point when one is set. */
    private function isRootMethodAt(int $index): bool
    {
        $node = $this->parentStack[$index] ?? null;
        if ($this->entry === null || $node === null) {
            return $node !== null && $this->isScheduleMethod($node);
        }

        $class = $this->parentStack[$index - 1] ?? null;

        return $node instanceof Node\Stmt\ClassMethod
            && $class instanceof Node\Stmt\ClassLike
            && $class->namespacedName !== null
            && Fqcn::same($class->namespacedName->toString(), (string) $this->entry->class)
            && Str::lower($node->name->toString()) === Str::lower((string) $this->entry->method);
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
