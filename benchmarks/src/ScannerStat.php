<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

/**
 * Timing and per-section entry counts for a single scanner's `scan()` pass.
 */
final readonly class ScannerStat
{
    /**
     * @param  array<string, int>  $sections  section name => entry count
     */
    public function __construct(
        public string $scanner,
        public float $milliseconds,
        public array $sections,
    ) {
    }

    public function entries(): int
    {
        return array_sum($this->sections);
    }
}
