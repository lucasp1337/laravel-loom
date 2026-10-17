<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A handler bound to an Eloquent model event: an
 * `Event::listen('eloquent.{hook}: {Model}', $handler)` entry, a `$listen`
 * entry with such a key, or a `static::{hook}($callback)` registration.
 *
 * A closure handler carries the model as `handler`, {@see self::CLOSURE_METHOD}
 * as `method` and its last line as `endLine`. `viaTrait` marks a registration
 * made inside a trait, where `model` is the trait and the scanner expands it
 * to the classes using it.
 *
 * @internal
 */
final readonly class EloquentListenRecord
{
    public const string CLOSURE_METHOD = '{closure}';

    public function __construct(
        public string $model,
        public string $hook,
        public string $handler,
        public string $method,
        public int $line,
        public ?int $endLine = null,
        public bool $viaTrait = false,
    ) {}

    public function isClosure(): bool
    {
        return $this->endLine !== null;
    }
}
