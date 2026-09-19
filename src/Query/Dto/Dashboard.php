<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class Dashboard
{
    /**
     * @param  array<string, int>  $counts  per stats section, in registry order
     * @param  list<FanOut>  $biggestFanOut
     */
    public function __construct(
        public IndexMeta $meta,
        public array $counts,
        public int $orphanEventCount,
        public int $idleListenerCount,
        public int $unresolvedCount,
        public array $biggestFanOut,
    ) {
    }
}
