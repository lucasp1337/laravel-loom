<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Support\Arr;

/**
 * The measured outcome of benchmarking one fixture app: headline wall time and
 * peak memory for a full build, the per-scanner breakdown, and the final
 * section entry counts. The count data is deterministic and forms the gate a
 * baseline assertion checks; timings are informational.
 */
final readonly class BenchResult
{
    /**
     * @param  list<ScannerStat>  $scanners
     * @param  array<string, int>  $sections  section name => entry count (Index stats)
     */
    public function __construct(
        public string $profile,
        public int $files,
        public float $buildMilliseconds,
        public int $peakMemoryBytes,
        public array $scanners,
        public array $sections,
    ) {
    }

    /**
     * The deterministic fingerprint a baseline assertion compares: total files,
     * section counts, and per-scanner section counts. Timings are excluded.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'files' => $this->files,
            'sections' => $this->sections,
            'scanners' => Arr::mapWithKeys(
                $this->scanners,
                fn (ScannerStat $stat): array => [$stat->scanner => $stat->sections],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'files' => $this->files,
            'build_ms' => round($this->buildMilliseconds, 2),
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'sections' => $this->sections,
            'scanners' => Arr::map(
                $this->scanners,
                fn (ScannerStat $stat): array => [
                    'scanner' => $stat->scanner,
                    'ms' => round($stat->milliseconds, 2),
                    'entries' => $stat->entries(),
                    'sections' => $stat->sections,
                ],
            ),
        ];
    }
}
