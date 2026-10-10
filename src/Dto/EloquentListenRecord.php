<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A `Event::listen('eloquent.{hook}: {Model}', $handler)` entry.
 *
 * @internal
 */
final readonly class EloquentListenRecord
{
    public function __construct(
        public string $model,
        public string $hook,
        public string $handler,
        public string $method,
        public int $line,
    ) {}
}
