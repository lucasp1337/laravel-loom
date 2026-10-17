<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClosurePairRecord;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\EventsDispatcher;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\EloquentEventName;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;

/**
 * Collects (event, listener) pairs from Event::listen(...) calls and the
 * equivalent container forms (`$this->app['events']->listen(...)`,
 * `app(Dispatcher::class)->listen(...)`, `$dispatcher->listen(...)`).
 *
 * The surrounding class is not checked, so providers in any layout count. The
 * receiver is the `Event` facade (FQCN or bare alias) or a dispatcher:
 * `$this->app['events']`, `app(Dispatcher::class)`, `resolve(Dispatcher::class)`,
 * `$this->app->make/makeWith(Dispatcher::class)`, or a local variable assigned
 * from one of those earlier in the same file (a single flat pass; reassigning
 * it to something else ends tracking). `Dispatcher` is matched by
 * `Illuminate\Contracts\Events\Dispatcher`, `Illuminate\Events\Dispatcher` or
 * the bare basename. A dispatcher typed in a `boot()` parameter or held in a
 * property is not tracked.
 *
 * The first argument is an event class-string, a literal event name or an
 * array of them (`eloquent.*` names belong to ObserverScanner); a closure
 * or arrow function as the first argument infers the event from its first
 * parameter's class type (nullable unwrapped, union gives one per class,
 * untyped, `object` and `mixed` skipped). The second argument takes the same
 * shapes as `$listen` values, or a closure, which becomes a closure listener.
 * An event or listener of any other shape is recorded as unresolved.
 *
 * @internal
 */
final class EventListenCallVisitor extends CollectingVisitor
{
    use RecordsUnresolvedRegistrations;

    /** @var list<ListenerPair> */
    private array $pairs = [];

    /** @var list<ClosurePairRecord> */
    private array $closurePairs = [];

    /** @var array<string, true> Variables proven to hold a Dispatcher in scope. */
    private array $dispatcherVars = [];

    protected function reset(): void
    {
        $this->pairs = [];
        $this->closurePairs = [];
        $this->dispatcherVars = [];
        $this->resetUnresolved();
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->handleMethodCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->handleAssign($node);
        }

        return null;
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }
        if (! Facades::EVENT->matches($node->class->toString())) {
            return;
        }
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        if ($node->name->toString() !== 'listen') {
            return;
        }
        if (count($node->args) < 1) {
            return;
        }

        $this->handleListenArgs(Args::of($node->args));
    }

    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        if ($node->name->toString() !== 'listen') {
            return;
        }
        if (count($node->args) < 1) {
            return;
        }
        if (! EventsDispatcher::isReceiver($node->var, $this->dispatcherVars)) {
            return;
        }

        $this->handleListenArgs(Args::of($node->args));
    }

    private function handleAssign(Node\Expr\Assign $node): void
    {
        if (! $node->var instanceof Node\Expr\Variable || ! is_string($node->var->name)) {
            return;
        }

        if (EventsDispatcher::isReceiver($node->expr, $this->dispatcherVars)) {
            $this->dispatcherVars[$node->var->name] = true;

            return;
        }

        // Reassignment to a non-Dispatcher value invalidates the tracked binding.
        unset($this->dispatcherVars[$node->var->name]);
    }

    /**
     * Extract the (event, listener) pair from a `listen(event, listener)`
     * arg list, shared by the facade and container receiver forms.
     */
    private function handleListenArgs(Args $args): void
    {
        $first = $args->valueAt(0);
        if ($first === null) {
            return;
        }

        // Inferred form: the event comes from the closure's first parameter type.
        if ($first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction) {
            $events = $this->eventsFromClosureType($first);
            if ($events === []) {
                $this->recordUnresolved($first, UnresolvedReason::UNTYPED_CLOSURE_LISTENER);
            }
            foreach ($events as $event) {
                $this->recordClosurePair($event, $first);
            }

            return;
        }

        $second = $args->valueAt(1);
        if ($second === null) {
            // A lone non-closure argument (e.g. `queueable(...)`) names no readable event.
            $this->recordUnresolved($first, UnresolvedReason::UNREADABLE_EVENT_NAME);

            return;
        }

        // Array first arg: one listener bound to several events.
        if ($first instanceof Node\Expr\Array_) {
            foreach ($first->items as $item) {
                $this->recordListen($item->value, $second);
            }

            return;
        }

        $this->recordListen($first, $second);
    }

    private function recordListen(Node\Expr $eventExpr, Node\Expr $listener): void
    {
        $event = ListenerRef::event($eventExpr);
        if ($event === null) {
            $this->recordUnresolved($eventExpr, UnresolvedReason::UNREADABLE_EVENT_NAME);

            return;
        }

        if ($listener instanceof Node\Expr\Closure || $listener instanceof Node\Expr\ArrowFunction) {
            $this->recordClosurePair($event, $listener);

            return;
        }

        // `eloquent.{hook}: {Model}` names are model-event handlers; ObserverScanner reads them.
        if (EloquentEventName::is($event)) {
            return;
        }

        $resolved = ListenerRef::listener($listener);
        if ($resolved === null) {
            $this->recordUnresolved($listener, UnresolvedReason::UNREADABLE_LISTENER);

            return;
        }

        $this->pairs[] = new ListenerPair(
            event: $event,
            listener: $resolved['listener'],
            method: $resolved['method'],
        );
    }

    /**
     * Event classes named by the closure's first parameter type (union: one each).
     * Untyped, builtin-typed (`object`, `mixed`) and non-class hints yield none.
     *
     * @return list<string>
     */
    private function eventsFromClosureType(Node\Expr\Closure|Node\Expr\ArrowFunction $closure): array
    {
        $type = ($closure->params[0] ?? null)?->type;
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        $candidates = $type instanceof Node\UnionType ? $type->types : [$type];

        $events = [];
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof Node\Name) {
                continue;
            }
            $events[] = $candidate->toString();
        }

        return array_values(array_unique($events));
    }

    private function recordClosurePair(string $event, Node\Expr\Closure|Node\Expr\ArrowFunction $closure): void
    {
        $this->closurePairs[] = new ClosurePairRecord(
            event: $event,
            line: $closure->getStartLine(),
            endLine: $closure->getEndLine(),
            registration: ListenerRegistration::EVENT_LISTEN_CALL,
        );
    }

    /** @return list<ListenerPair> */
    public function getPairs(): array
    {
        return $this->pairs;
    }

    /** @return list<ClosurePairRecord> */
    public function getClosurePairs(): array
    {
        return $this->closurePairs;
    }
}
