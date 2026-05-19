<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A `Event::listen('eloquent.{hook}: {Model}', $handler)` entry. */
final class EloquentListenRecord
{
    public function __construct(
        public readonly string $model,
        public readonly string $hook,
        public readonly string $handler,
        public readonly string $method,
        public readonly int $line,
    ) {
    }
}
