<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

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
            $listenerFqcn = $listener['fqcn'] ?? null;
            $handles = $listener['handles'] ?? [];
            if (! is_string($listenerFqcn) || ! is_array($handles)) {
                continue;
            }

            foreach ($handles as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $eventFqcn = $pair['event'] ?? null;
                $method = $pair['method'] ?? null;
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
        $handledBy = $context->sections[Sections::EVENTS->value][$eIdx]['handled_by'] ?? [];

        foreach ($handledBy as $existing) {
            if ($existing['listener'] === $listenerFqcn && $existing['method'] === $method) {
                return;
            }
        }

        $handledBy[] = ['listener' => $listenerFqcn, 'method' => $method];
        $context->sections[Sections::EVENTS->value][$eIdx]['handled_by'] = $handledBy;
    }
}
