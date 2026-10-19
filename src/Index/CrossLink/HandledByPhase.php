<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Index\UnresolvedReason;

/**
 * Inverts `listeners[*].handles` `{event, method}` pairs onto the
 * matching `events[*].handled_by` as `{listener, method}` pairs. Records each
 * listener's method set on the context for {@see DispatchAttributionPhase}.
 * Closure listeners are not joined: a `handled_by` entry needs a class name.
 *
 * A handled event class with no `events[]` row (a vendor event) stays in
 * `handles` and is listed once per listener in `unresolved_dispatches` with
 * reason `handled_event_not_indexed`, at the listener class. A string event
 * name (`'user.login'`, a wildcard) is not a class and is not reported.
 *
 * @internal
 */
final class HandledByPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $eventIndex = $context->index(Sections::EVENTS);

        $reported = [];
        foreach ($context->sections[Sections::LISTENERS->value] as $listener) {
            $listenerFqcn = $listener[Field::FQCN->value] ?? null;
            $handles = $listener[Field::HANDLES->value] ?? [];
            if (! is_string($listenerFqcn) || ! is_array($handles)) {
                continue;
            }

            foreach ($handles as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $eventFqcn = $pair[Field::EVENT->value] ?? null;
                $method = $pair[Field::METHOD->value] ?? null;
                if (! is_string($eventFqcn) || ! is_string($method)) {
                    continue;
                }

                $context->listenerMethods[$listenerFqcn][$method] = true;

                if (! isset($eventIndex[$eventFqcn])) {
                    $this->reportUnindexed($context, $listener, $eventFqcn, $reported);

                    continue;
                }
                $this->appendHandledBy($context, $eventIndex[$eventFqcn], $listenerFqcn, $method);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $listener
     * @param  array<string, true>  $reported
     */
    private function reportUnindexed(CrossLinkContext $context, array $listener, string $event, array &$reported): void
    {
        $file = $listener[Field::FILE->value] ?? null;
        $line = $listener[Field::LINE->value] ?? null;
        if (! $this->isClassName($event) || ! is_string($file) || ! is_int($line) || isset($reported[$file.':'.$line.':'.$event])) {
            return;
        }
        $reported[$file.':'.$line.':'.$event] = true;

        $context->sections[Sections::UNRESOLVED_DISPATCHES->value][] = [
            Field::FILE->value => $file,
            Field::LINE->value => $line,
            Field::EXPRESSION->value => $event.'::class',
            Field::REASON->value => UnresolvedReason::HANDLED_EVENT_NOT_INDEXED->value,
        ];
    }

    /** A namespaced class name, as opposed to a string event name or a wildcard. */
    private function isClassName(string $event): bool
    {
        return preg_match('/^\w+(?:\\\\\w+)+$/', $event) === 1;
    }

    private function appendHandledBy(CrossLinkContext $context, int $eIdx, string $listenerFqcn, string $method): void
    {
        /** @var array<int, array{listener: string, method: string}> $handledBy */
        $handledBy = $context->sections[Sections::EVENTS->value][$eIdx][Field::HANDLED_BY->value] ?? [];

        foreach ($handledBy as $existing) {
            if ($existing[Field::LISTENER->value] === $listenerFqcn && $existing[Field::METHOD->value] === $method) {
                return;
            }
        }

        $handledBy[] = [Field::LISTENER->value => $listenerFqcn, Field::METHOD->value => $method];
        $context->sections[Sections::EVENTS->value][$eIdx][Field::HANDLED_BY->value] = $handledBy;
    }
}
