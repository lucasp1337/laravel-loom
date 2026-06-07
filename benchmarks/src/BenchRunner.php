<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Support\Arr;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

/**
 * Benchmarks a fixture app: a full {@see IndexBuilder} build for the headline
 * wall time and peak memory, plus an isolated `scan()` pass per scanner for the
 * cost breakdown. Both passes run the same {@see DefaultScanners} set that
 * `loom:scan` uses, so the numbers reflect production behaviour.
 */
final class BenchRunner
{
    public function run(BenchProfile $profile, string $appRoot, string $laravelVersion = '12.x'): BenchResult
    {
        // The scanner pass runs first deliberately: it warms the opcache and
        // realpath caches so the headline build time reflects steady-state
        // (repeat-run) cost rather than first-touch I/O.
        $scanners = $this->measureScanners($appRoot);
        [$buildMs, $peakBytes, $sections] = $this->measureBuild($appRoot, $laravelVersion);

        return new BenchResult(
            profile: $profile->name,
            files: $profile->totalFiles(),
            buildMilliseconds: $buildMs,
            peakMemoryBytes: $peakBytes,
            scanners: $scanners,
            sections: $sections,
        );
    }

    /**
     * @return list<ScannerStat>
     */
    private function measureScanners(string $appRoot): array
    {
        $stats = [];
        foreach (DefaultScanners::all() as $scanner) {
            $start = hrtime(true);
            $result = $scanner->scan($appRoot);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $sections = Arr::mapWithKeys(
                $result,
                fn (array $entries, string $section): array => [$section => count($entries)],
            );

            $stats[] = new ScannerStat(class_basename($scanner), $elapsedMs, $sections);
        }

        return $stats;
    }

    /**
     * @return array{0: float, 1: int, 2: array<string, int>}
     */
    private function measureBuild(string $appRoot, string $laravelVersion): array
    {
        $builder = new IndexBuilder;
        DefaultScanners::registerOn($builder);

        gc_collect_cycles();
        memory_reset_peak_usage();

        $start = hrtime(true);
        $index = $builder->build($appRoot, $laravelVersion);
        $buildMs = (hrtime(true) - $start) / 1_000_000;

        $peakBytes = memory_get_peak_usage(true);

        /** @var array<string, int> $sections */
        $sections = $index->toArray()['stats'] ?? [];

        return [$buildMs, $peakBytes, $sections];
    }
}
