<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\EloquentListenRecord;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\EloquentEventName;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;

/**
 * Emits model-event entries from `Event::listen('eloquent.{hook}: {Model}', $handler)`
 * and from `$listen` entries with such a key on an EventServiceProvider.
 *
 * The event is a literal string with or without the space after the colon, and
 * the hook must be one of the model hooks (booting and booted included). The
 * handler is `'Class@method'`, `Class::class.'@method'`, `[Class::class, 'method']`
 * or a bare `Class::class` whose method is `handle`, as for any class listener.
 * A closure handler is recorded with the `{closure}` method; its body is the
 * closure listener ListenerScanner emits. These entries feed `model_events[]`
 * only: a handler is never promoted to `observers[]`, because it may not be an
 * observer class. An `eloquent.` name with an unknown hook, or a handler that
 * is not readable, is recorded as unresolved.
 *
 * @internal
 */
final class EloquentListenStringVisitor extends CollectingVisitor
{
    use IdentifiesEventServiceProvider;
    use RecordsUnresolvedRegistrations;

    /** @var list<EloquentListenRecord> */
    private array $entries = [];

    protected function reset(): void
    {
        $this->entries = [];
        $this->resetUnresolved();
    }

    public function enterNode(Node $node): null
    {
        $this->enterClassScope($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleListenCall($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $this->handleListenProperty($node);

            return null;
        }

        $this->leaveClassScope($node);

        return null;
    }

    private function handleListenCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name || ! Facades::EVENT->matches($node->class->toString())) {
            return;
        }
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'listen') {
            return;
        }

        $args = Args::of($node->args);
        $eventValue = $args->valueAt(0);
        $handlerValue = $args->valueAt(1);
        if ($eventValue === null || $handlerValue === null) {
            return;
        }

        $this->collect($eventValue, $handlerValue, $node->getStartLine());
    }

    private function handleListenProperty(Node\Stmt\Property $node): void
    {
        if (! $this->inEventServiceProvider()) {
            return;
        }

        foreach ($node->props as $prop) {
            if ($prop->name->toString() !== 'listen' || ! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($prop->default->items as $item) {
                if ($item->key === null) {
                    continue;
                }

                $handlers = $item->value instanceof Node\Expr\Array_
                    ? array_map(static fn (Node\ArrayItem $handler): Node\Expr => $handler->value, $item->value->items)
                    : [$item->value];

                foreach ($handlers as $handler) {
                    $this->collect($item->key, $handler, $handler->getStartLine());
                }
            }
        }
    }

    private function collect(Node\Expr $eventValue, Node\Expr $handlerValue, int $line): void
    {
        $name = ListenerRef::event($eventValue);
        // Not a readable name, or not a model event: the listener visitors own those.
        if ($name === null || ! EloquentEventName::is($name)) {
            return;
        }

        $event = EloquentEventName::parse($name);
        if ($event === null) {
            $this->recordUnresolved($eventValue, UnresolvedReason::UNREADABLE_MODEL_HOOK);

            return;
        }

        if ($handlerValue instanceof Node\Expr\Closure || $handlerValue instanceof Node\Expr\ArrowFunction) {
            $this->entries[] = new EloquentListenRecord(
                model: $event['model'],
                hook: $event['hook']->value,
                handler: $event['model'],
                method: EloquentListenRecord::CLOSURE_METHOD,
                line: $handlerValue->getStartLine(),
                endLine: $handlerValue->getEndLine(),
            );

            return;
        }

        $resolved = ListenerRef::listener($handlerValue);
        if ($resolved === null) {
            $this->recordUnresolved($handlerValue, UnresolvedReason::UNREADABLE_LISTENER);

            return;
        }

        $this->entries[] = new EloquentListenRecord(
            model: $event['model'],
            hook: $event['hook']->value,
            handler: $resolved['listener'],
            method: $resolved['method'],
            line: $line,
        );
    }

    /** @return list<EloquentListenRecord> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
