<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A (model, observers, line) record from `observe()` or `#[ObservedBy]`.
 *
 * @internal
 */
final readonly class ObserverPair
{
    /**
     * @param  list<string>  $observers
     */
    public function __construct(
        public string $model,
        public array $observers,
        public int $line,
    ) {}
}
