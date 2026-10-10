<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class Dashboard
{
    /**
     * @param  array<string, int>  $stats  entry count per section, in index body order, as in the index `stats` block
     * @param  list<FanOut>  $biggestFanOut
     */
    public function __construct(
        public IndexMeta $meta,
        public array $stats,
        public int $orphanEventCount,
        public int $idleListenerCount,
        public int $unresolvedDispatchCount,
        public array $biggestFanOut,
    ) {}
}
