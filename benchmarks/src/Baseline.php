<?php

declare(strict_types=1);

namespace Lucasp\Loom\Benchmarks;

use Illuminate\Support\Arr;

/**
 * Reads, writes, and asserts against the committed `baseline.json`. The gate is
 * the deterministic count fingerprint — total files, section counts, and
 * per-scanner section counts. A mismatch means a scanner started seeing the
 * fixtures differently, which is a real regression. Wall-clock timings are
 * hardware-dependent and only compared when an explicit threshold is supplied
 * (meaningful for same-machine A/B runs, noise across machines).
 */
final class Baseline
{
    private const NOTE = 'Counts are deterministic and asserted by `composer bench:assert`. '
        .'reference_ms is informational — wall time is hardware-dependent and only '
        .'gated when --time-threshold is passed on the same machine.';

    /**
     * @param  list<BenchResult>  $results
     */
    public static function build(array $results): string
    {
        $profiles = [];
        foreach ($results as $result) {
            $profiles[$result->profile] = $result->fingerprint() + [
                'reference_ms' => round($result->buildMilliseconds, 2),
            ];
        }

        return (string) json_encode(
            ['_note' => self::NOTE, 'profiles' => $profiles],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /**
     * @param  array<string, mixed>  $baseline  decoded baseline.json
     * @param  list<BenchResult>  $results
     * @return list<string> regression messages; empty when all checks pass
     */
    public static function compare(array $baseline, array $results, ?float $timeThreshold = null): array
    {
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $baseline['profiles'] ?? [];
        $failures = [];

        foreach ($results as $result) {
            $expected = $profiles[$result->profile] ?? null;
            if ($expected === null) {
                $failures[] = "[{$result->profile}] no baseline entry — run `composer bench:baseline`.";

                continue;
            }

            $failures = [...$failures, ...self::compareCounts($result, $expected)];

            if ($timeThreshold !== null) {
                $failures = [...$failures, ...self::compareTime($result, $expected, $timeThreshold)];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private static function compareCounts(BenchResult $result, array $expected): array
    {
        $want = Arr::only($expected, ['files', 'sections', 'scanners']);
        $got = $result->fingerprint();

        if ($want === $got) {
            return [];
        }

        $failures = [];
        if (($want['files'] ?? null) !== $got['files']) {
            $failures[] = "[{$result->profile}] file count drifted: baseline {$want['files']}, got {$got['files']}.";
        }
        foreach (self::countDiffs($want['sections'] ?? [], $got['sections']) as $diff) {
            $failures[] = "[{$result->profile}] section {$diff}";
        }

        // Iterate the key union so a scanner that vanishes from the results
        // (removed from DefaultScanners, or renamed) is reported, not skipped.
        /** @var array<string, array<string, int>> $gotScanners */
        $gotScanners = $got['scanners'];
        /** @var array<string, array<string, int>> $wantScanners */
        $wantScanners = is_array($want['scanners'] ?? null) ? $want['scanners'] : [];
        foreach (array_keys($gotScanners + $wantScanners) as $scanner) {
            $base = $wantScanners[$scanner] ?? [];
            $now = $gotScanners[$scanner] ?? [];
            if ($base !== $now) {
                foreach (self::countDiffs($base, $now) as $diff) {
                    $failures[] = "[{$result->profile}] {$scanner} {$diff}";
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, int>  $want
     * @param  array<string, int>  $got
     * @return list<string>
     */
    private static function countDiffs(array $want, array $got): array
    {
        $diffs = [];
        foreach ($got + $want as $key => $_) {
            $a = $want[$key] ?? 0;
            $b = $got[$key] ?? 0;
            if ($a !== $b) {
                $diffs[] = "{$key}: baseline {$a}, got {$b}.";
            }
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private static function compareTime(BenchResult $result, array $expected, float $threshold): array
    {
        $reference = $expected['reference_ms'] ?? null;
        if (! is_numeric($reference) || $reference <= 0.0) {
            return [];
        }

        $ratio = ($result->buildMilliseconds - (float) $reference) / (float) $reference;
        if ($ratio > $threshold) {
            $pct = number_format($ratio * 100, 0);
            $limit = number_format($threshold * 100, 0);

            return ["[{$result->profile}] build time +{$pct}% vs baseline (limit {$limit}%)."];
        }

        return [];
    }
}
