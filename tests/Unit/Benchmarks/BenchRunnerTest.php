<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Lucasp\Loom\Benchmarks\AppGenerator;
use Lucasp\Loom\Benchmarks\BenchProfile;
use Lucasp\Loom\Benchmarks\BenchResult;
use Lucasp\Loom\Benchmarks\BenchRunner;
use Lucasp\Loom\Benchmarks\ScannerStat;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/loom-bench-runner-'.uniqid('', true);
    (new AppGenerator)->generate(BenchProfile::tiny(), $this->dir);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->dir);
});

it('benchmarks the tiny app into a populated BenchResult', function () {
    $profile = BenchProfile::tiny();

    $result = (new BenchRunner)->run($profile, $this->dir);

    expect($result)->toBeInstanceOf(BenchResult::class);
    expect($result->profile)->toBe('tiny');
    expect($result->files)->toBe($profile->totalFiles());
    expect($result->buildMilliseconds)->toBeGreaterThan(0);
    expect($result->peakMemoryBytes)->toBeGreaterThan(0);
    expect($result->sections)->not->toBe([]);
    expect($result->sections['events'])->toBe($profile->count('events'));
});

it('records a stat for each of the nine default scanners', function () {
    $result = (new BenchRunner)->run(BenchProfile::tiny(), $this->dir);

    expect($result->scanners)->toHaveCount(9);

    $names = array_map(fn (ScannerStat $s): string => $s->scanner, $result->scanners);

    expect($names)->toContain(
        'EventScanner',
        'ListenerScanner',
        'ObserverScanner',
        'JobsScanner',
        'MailableScanner',
        'NotificationScanner',
        'DispatchScanner',
        'ScheduleScanner',
        'RouteScanner',
    );
});

it('produces a reproducible fingerprint across runs over the same app', function () {
    $runner = new BenchRunner;

    $first = $runner->run(BenchProfile::tiny(), $this->dir);
    $second = $runner->run(BenchProfile::tiny(), $this->dir);

    expect($second->fingerprint())->toBe($first->fingerprint());
});
