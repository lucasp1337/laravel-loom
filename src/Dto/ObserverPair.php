<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A (model, observers, line) record from `observe()` or `#[ObservedBy]`. */
final class ObserverPair
{
    /**
     * @param  list<string>  $observers
     */
    public function __construct(
        public readonly string $model,
        public readonly array $observers,
        public readonly int $line,
    ) {
    }
}
