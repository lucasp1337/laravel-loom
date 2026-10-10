<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * One `$dispatchesEvents` entry: a model lifecycle hook mapped to an event class.
 *
 * @internal
 */
final readonly class DispatchesEventsMapping
{
    public function __construct(
        public string $modelFqcn,
        public string $hook,
        public string $eventFqcn,
        public int $line,
    ) {}
}
