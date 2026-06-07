<?php

declare(strict_types=1);

/**
 * Loom benchmark runner. Generates tiny/medium/large fixture apps, scans each
 * with the production scanner set, and reports wall time, peak memory, and a
 * per-scanner breakdown. See benchmarks/README.md.
 *
 *   php benchmarks/bench.php                  # run all sizes, print a table
 *   php benchmarks/bench.php --size=medium    # one size only
 *   php benchmarks/bench.php --json           # machine-readable output
 *   php benchmarks/bench.php --baseline       # (re)write baseline.json
 *   php benchmarks/bench.php --assert         # fail on count drift vs baseline
 *   php benchmarks/bench.php --assert --time-threshold=0.5   # also gate wall time
 */

use Lucasp\Loom\Benchmarks\AppGenerator;
use Lucasp\Loom\Benchmarks\Baseline;
use Lucasp\Loom\Benchmarks\BenchProfile;
use Lucasp\Loom\Benchmarks\BenchReport;
use Lucasp\Loom\Benchmarks\BenchRunner;

require __DIR__.'/../vendor/autoload.php';

/** @var array<int, string> $argv */
$args = array_slice($argv, 1);

$flag = static fn (string $name): bool => in_array("--{$name}", $args, true);

$option = static function (string $name) use ($args): ?string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
};

$baselinePath = __DIR__.'/baseline.json';
$outRoot = $option('out-dir') ?? sys_get_temp_dir().'/loom-bench';
$keep = $flag('keep');

$size = $option('size');
$profiles = $size !== null ? [BenchProfile::named($size)] : BenchProfile::all();

$generator = new AppGenerator;
$runner = new BenchRunner;

$results = [];
foreach ($profiles as $profile) {
    $appDir = $outRoot.'/'.$profile->name;
    if (! $keep || ! is_dir($appDir)) {
        fwrite(STDERR, "generating {$profile->name} ({$profile->totalFiles()} files)...\n");
        $generator->generate($profile, $appDir);
    }
    fwrite(STDERR, "scanning {$profile->name}...\n");
    $results[] = $runner->run($profile, $appDir);
}

if ($flag('baseline')) {
    file_put_contents($baselinePath, Baseline::build($results));
    fwrite(STDERR, "baseline written to {$baselinePath}\n");
    exit(0);
}

if ($flag('assert')) {
    $raw = is_file($baselinePath) ? file_get_contents($baselinePath) : false;
    if ($raw === false) {
        fwrite(STDERR, "no baseline.json — run `composer bench:baseline` first.\n");
        exit(2);
    }

    /** @var array<string, mixed> $baseline */
    $baseline = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $threshold = $option('time-threshold');

    $failures = Baseline::compare($baseline, $results, $threshold !== null ? (float) $threshold : null);
    if ($failures !== []) {
        fwrite(STDERR, "benchmark regression:\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, "  - {$failure}\n");
        }
        exit(1);
    }

    fwrite(STDERR, "✓ benchmark counts match baseline\n");
    exit(0);
}

echo $flag('json') ? BenchReport::json($results) : BenchReport::text($results);
echo "\n";
