<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * One `$dispatchesEvents` entry: a model lifecycle hook mapped to an event class.
 *
 * @internal
 */
final class DispatchesEventsMapping
{
    public function __construct(
        public readonly string $modelFqcn,
        public readonly string $hook,
        public readonly string $eventFqcn,
        public readonly int $line,
    ) {}
}
