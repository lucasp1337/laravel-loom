<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Lucasp\Loom\Mcp\IndexRepository;
use Symfony\Component\Console\Output\BufferedOutput;

function loomTempDir(): string
{
    $dir = sys_get_temp_dir().'/loom-cmd-'.bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

it('writes the index atomically without leaving temp files', function () {
    $dir = loomTempDir();
    config()->set('loom.index_path', $dir.'/nested/index.json');

    expect(Artisan::call('loom:scan', ['--allow-empty' => true]))->toBe(0)
        ->and(json_decode((string) file_get_contents($dir.'/nested/index.json'), true))->toBeArray()
        ->and(scandir($dir.'/nested'))->toEqualCanonicalizing(['.', '..', 'index.json']);
});

it('refuses --snapshot combined with --scan', function () {
    $this->artisan('loom:mcp', ['--snapshot' => '/tmp/x.json', '--scan' => true])
        ->expectsOutputToContain('cannot be combined')
        ->assertExitCode(1);
});

it('fails fast with --no-scan when the snapshot is missing', function () {
    $this->artisan('loom:mcp', ['--snapshot' => '/tmp/loom-missing-'.bin2hex(random_bytes(4)).'.json', '--no-scan' => true])
        ->expectsOutputToContain('--no-scan')
        ->assertExitCode(1);
});

it('scans silently before serving so stdout carries only the server', function () {
    $dir = loomTempDir();
    config()->set('loom.index_path', $dir.'/index.json');
    app()->forgetInstance(IndexRepository::class);

    $output = new BufferedOutput;

    expect(Artisan::call('loom:mcp', ['--scan' => true, '--allow-empty' => true], $output))->toBe(0)
        ->and($output->fetch())->not->toContain('Loom index written')
        ->and(is_file($dir.'/index.json'))->toBeTrue();
});

it('does not serve when --scan finds no PHP files and --allow-empty is not set', function () {
    $dir = loomTempDir();
    config()->set('loom.index_path', $dir.'/index.json');
    app()->forgetInstance(IndexRepository::class);

    $this->artisan('loom:mcp', ['--scan' => true])
        ->expectsOutputToContain('loom:scan failed')
        ->assertExitCode(1);

    expect(is_file($dir.'/index.json'))->toBeFalse();
});

it('passes --allow-empty to the auto-scan', function () {
    $dir = loomTempDir();
    config()->set('loom.index_path', $dir.'/index.json');
    app()->forgetInstance(IndexRepository::class);

    $repository = app(IndexRepository::class);
    $repository->scanWith(['--allow-empty' => true]);

    expect($repository->payload())->toBeArray()
        ->and(is_file($dir.'/index.json'))->toBeTrue();
});
