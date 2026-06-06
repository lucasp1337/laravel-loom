<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Phase 1 — inverts `listeners[*].handles` `{event, method}` pairs onto the
 * matching `events[*].handled_by` as `{listener, method}` pairs. Records each
 * listener's method set on the context for {@see DispatchAttributionPhase}.
 */
final class HandledByPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $eventIndex = $context->index(Sections::EVENTS);

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
                    continue;
                }
                $this->appendHandledBy($context, $eventIndex[$eventFqcn], $listenerFqcn, $method);
            }
        }
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
