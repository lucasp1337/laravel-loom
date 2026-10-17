<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClosurePairRecord;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\EloquentEventName;
use PhpParser\Node;

/**
 * Collects (event, listener) pairs from `$listen` on EventServiceProvider classes.
 * Keys are `Event::class` constants or literal strings (a class name, dotted
 * name or wildcard pattern). `eloquent.*` keys belong to ObserverScanner.
 *
 * Values may be a bare `Listener::class` (method `handle`), a `'Fqcn@method'`
 * string or `Listener::class.'@method'`, a tuple `[Listener::class, 'method']`,
 * `Closure::fromCallable([...])` (the one-element form defaults to `handle`) or
 * `Listener::method(...)`; all resolve to the same pair. Closures and arrow
 * functions are collected separately as closure listeners. A key or value that
 * is none of these is recorded as unresolved. The property may have any
 * visibility and must be an array.
 *
 * @internal
 */
final class ListenArrayVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;
    use RecordsUnresolvedRegistrations;

    /** @var list<ListenerPair> */
    private array $pairs = [];

    /** @var list<ClosurePairRecord> */
    private array $closurePairs = [];

    protected function reset(): void
    {
        $this->pairs = [];
        $this->closurePairs = [];
        $this->resetUnresolved();
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        $this->leaveClassScope($node);

        return null;
    }

    private function handleProperty(Node\Stmt\Property $node): void
    {
        if (! $this->inEventServiceProvider()) {
            return;
        }

        foreach ($node->props as $prop) {
            if ($prop->name->toString() !== 'listen') {
                continue;
            }
            if (! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            $this->collectPairs($prop->default);
        }
    }

    private function collectPairs(Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            // A keyless item is not an event name.
            if ($item->key === null) {
                continue;
            }

            $event = ListenerRef::event($item->key);
            if ($event === null) {
                $this->recordUnresolved($item->key, UnresolvedReason::UNREADABLE_LISTENER);

                continue;
            }

            $value = $item->value;
            $listeners = $value instanceof Node\Expr\Array_
                ? array_map(static fn (Node\ArrayItem $listener): Node\Expr => $listener->value, $value->items)
                : [$value];

            foreach ($listeners as $listener) {
                $this->emitListenerEntry($event, $listener);
            }
        }
    }

    private function emitListenerEntry(string $event, Node\Expr $value): void
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            $this->closurePairs[] = new ClosurePairRecord(
                event: $event,
                line: $value->getStartLine(),
                endLine: $value->getEndLine(),
                registration: ListenerRegistration::LISTEN_ARRAY,
            );

            return;
        }

        // `eloquent.{hook}: {Model}` keys are model-event handlers; ObserverScanner reads them.
        if (EloquentEventName::is($event)) {
            return;
        }

        $resolved = ListenerRef::listener($value);
        if ($resolved === null) {
            $this->recordUnresolved($value, UnresolvedReason::UNREADABLE_LISTENER);

            return;
        }

        $this->pairs[] = new ListenerPair(
            event: $event,
            listener: $resolved['listener'],
            method: $resolved['method'],
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
