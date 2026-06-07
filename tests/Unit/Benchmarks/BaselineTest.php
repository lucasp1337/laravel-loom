<?php

declare(strict_types=1);

use Lucasp\Loom\Benchmarks\Baseline;
use Lucasp\Loom\Benchmarks\BenchResult;
use Lucasp\Loom\Benchmarks\ScannerStat;

/**
 * Hand-built result with full control over counts and timing.
 *
 * @param  array<string, int>  $sections
 */
function benchResult(
    string $profile = 'tiny',
    int $files = 19,
    float $buildMs = 100.0,
    array $sections = ['events' => 2, 'listeners' => 2],
): BenchResult {
    return new BenchResult(
        profile: $profile,
        files: $files,
        buildMilliseconds: $buildMs,
        peakMemoryBytes: 1_000_000,
        scanners: [
            new ScannerStat('EventScanner', 5.0, ['events' => 2]),
            new ScannerStat('ListenerScanner', 5.0, ['listeners' => 2]),
        ],
        sections: $sections,
    );
}

/**
 * @return array<string, mixed>
 */
function decodeBaseline(string $json): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('passes when comparing results against a baseline built from them', function () {
    $results = [benchResult()];

    $baseline = decodeBaseline(Baseline::build($results));

    expect(Baseline::compare($baseline, $results))->toBe([]);
});

it('flags a baseline missing the profile entry', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult('tiny')]));

    $failures = Baseline::compare($baseline, [benchResult('medium')]);

    expect($failures)->not->toBe([]);
    expect(implode("\n", $failures))->toContain('no baseline entry');
});

it('detects a drifted section count', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult()]));

    $drifted = benchResult(sections: ['events' => 99, 'listeners' => 2]);
    $failures = Baseline::compare($baseline, [$drifted]);

    expect($failures)->not->toBe([]);
    expect(implode("\n", $failures))->toContain('events');
});

it('detects a drifted file count', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult(files: 19)]));

    $failures = Baseline::compare($baseline, [benchResult(files: 42)]);

    expect($failures)->not->toBe([]);
    expect(implode("\n", $failures))->toContain('file count drifted');
});

it('detects a tampered baseline by mutating its encoded section count', function () {
    $decoded = decodeBaseline(Baseline::build([benchResult()]));
    $decoded['profiles']['tiny']['sections']['events'] = 999;

    $failures = Baseline::compare($decoded, [benchResult()]);

    expect($failures)->not->toBe([]);
    expect(implode("\n", $failures))->toContain('events');
});

it('ignores timings unless a threshold is supplied', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult(buildMs: 100.0)]));

    // 10x slower, but no threshold => counts-only gate passes.
    $failures = Baseline::compare($baseline, [benchResult(buildMs: 1000.0)]);

    expect($failures)->toBe([]);
});

it('reports a time regression beyond the threshold', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult(buildMs: 100.0)]));

    $failures = Baseline::compare($baseline, [benchResult(buildMs: 200.0)], timeThreshold: 0.5);

    expect($failures)->not->toBe([]);
    expect(implode("\n", $failures))->toContain('build time');
});

it('accepts a time within the threshold', function () {
    $baseline = decodeBaseline(Baseline::build([benchResult(buildMs: 100.0)]));

    $failures = Baseline::compare($baseline, [benchResult(buildMs: 120.0)], timeThreshold: 0.5);

    expect($failures)->toBe([]);
});
