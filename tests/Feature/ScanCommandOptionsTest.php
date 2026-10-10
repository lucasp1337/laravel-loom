<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

function scanCommandSetup(string $fixture): string
{
    $out = sys_get_temp_dir().'/loom-scan-'.bin2hex(random_bytes(6)).'/index.json';
    app()->setBasePath(dirname(__DIR__).'/Fixtures/'.$fixture);
    config()->set('loom.index_path', $out);

    return $out;
}

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runScan(array $options = [], int $verbosity = BufferedOutput::VERBOSITY_NORMAL): array
{
    $output = new BufferedOutput($verbosity);
    $code = Artisan::call('loom:scan', $options, $output);

    return [$code, $output->fetch()];
}

it('reads scan.paths and scan.exclude from config', function () {
    $out = scanCommandSetup('modules-fixture-app');
    config()->set('loom.scan.paths', ['Modules/*']);
    config()->set('loom.scan.exclude', ['**/Jobs']);

    [$code] = runScan();
    $index = json_decode((string) file_get_contents($out), true);

    expect($code)->toBe(0)
        ->and($index['events'])->toHaveCount(1)
        ->and($index['jobs'])->toBe([]);
});

it('--path overrides scan.paths and is repeatable', function () {
    $out = scanCommandSetup('modules-fixture-app');
    config()->set('loom.scan.paths', ['app']);

    [$code] = runScan(['--path' => ['Modules/Billing/Events', 'Modules/Billing/Jobs']]);
    $index = json_decode((string) file_get_contents($out), true);

    // Events/ and Jobs/ resolve inside a scan path, so the bare directories contribute nothing.
    expect($code)->toBe(0)->and($index['events'])->toBe([]);

    [$code] = runScan(['--path' => ['Modules/Billing']]);
    $index = json_decode((string) file_get_contents($out), true);

    expect($code)->toBe(0)->and($index['events'])->toHaveCount(1)->and($index['jobs'])->toHaveCount(1);
});

it('--output overrides index_path, relative to the project root', function () {
    scanCommandSetup('modules-fixture-app');
    $absolute = sys_get_temp_dir().'/loom-out-'.bin2hex(random_bytes(6)).'/custom.json';

    [$code, $text] = runScan(['--output' => $absolute, '--path' => ['Modules/*']]);

    expect($code)->toBe(0)
        ->and($text)->toContain($absolute)
        ->and(is_file($absolute))->toBeTrue();

    $relative = 'loom-test-'.bin2hex(random_bytes(4)).'.json';
    [$code] = runScan(['--output' => $relative, '--path' => ['Modules/*']]);
    $written = app()->basePath($relative);

    expect($code)->toBe(0)->and(is_file($written))->toBeTrue();
    unlink($written);
});

it('prints a summary line with counts, unresolved dispatches and skipped files', function () {
    scanCommandSetup('modules-fixture-app');

    [$code, $text] = runScan(['--path' => ['Modules/*']]);

    expect($code)->toBe(0)
        ->and($text)->toContain('events: 1')
        ->and($text)->toContain('listeners: 1')
        ->and($text)->toContain('unresolved dispatches: 1')
        ->and($text)->toContain('skipped files: 0');
});

it('prints only a skipped-file count without -v', function () {
    scanCommandSetup('domain-fixture-app');

    [$code, $text] = runScan();

    expect($code)->toBe(0)
        ->and($text)->toContain('skipped files: 1 (-v lists them)')
        ->and($text)->not->toContain('Broken.php');
});

it('lists skipped files with file, line and parser message under -v', function () {
    scanCommandSetup('domain-fixture-app');

    [$code, $text] = runScan([], BufferedOutput::VERBOSITY_VERBOSE);

    expect($code)->toBe(0)
        ->and($text)->toContain('Skipped files:')
        ->and($text)->toMatch('#app/Broken\.php:\d+  .*syntax error#i');
});

it('counts a file once even though several scanners parse it', function () {
    scanCommandSetup('domain-fixture-app');

    [, $text] = runScan();

    expect($text)->toContain('skipped files: 1 ');
});

it('exits 1 when no scan directory exists', function () {
    scanCommandSetup('modules-fixture-app');

    [$code, $text] = runScan(['--path' => ['nowhere']]);

    expect($code)->toBe(1)->and($text)->toContain('No scan directory exists');
});

it('exits 1 for a scan path outside the project root', function (string $path) {
    $out = scanCommandSetup('modules-fixture-app');

    [$code, $text] = runScan(['--path' => [$path]]);

    expect($code)->toBe(1)
        ->and($text)->toContain('Scan path')
        ->and(is_file($out))->toBeFalse();
})->with(['../elsewhere', '/etc']);

it('exits 1 and writes nothing when the output cannot be written', function () {
    scanCommandSetup('modules-fixture-app');
    $blocker = tempnam(sys_get_temp_dir(), 'loom-blocker');

    [$code, $text] = runScan(['--output' => $blocker.'/index.json', '--path' => ['Modules/*']]);

    expect($code)->toBe(1)->and($text)->not->toContain('Loom index written');
    unlink($blocker);
});
