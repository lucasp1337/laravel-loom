<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;

/** @return array<string, string> fixture name => absolute path */
function deterministicFixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__).'/Fixtures/*-fixture-app', GLOB_ONLYDIR) ?: [] as $path) {
        $fixtures[basename($path)] = $path;
    }

    return $fixtures;
}

function scanToJson(string $root): string
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder);

    $index = $builder->build($root, '12.x');
    expect($builder->validate($index->toArray()))->toBe([]);

    return (string) json_encode($index->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/** Copy a tree creating entries in reverse order, so directory order differs from the source. */
function copyTreeReversed(string $from, string $to): void
{
    mkdir($to, 0777, true);
    $entries = array_values(array_diff(scandir($from) ?: [], ['.', '..']));
    foreach (array_reverse($entries) as $entry) {
        is_dir("{$from}/{$entry}")
            ? copyTreeReversed("{$from}/{$entry}", "{$to}/{$entry}")
            : copy("{$from}/{$entry}", "{$to}/{$entry}");
    }
}

function removeTree(string $dir): void
{
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") ? removeTree("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('emits byte-identical JSON for two scans of the same source', function (string $name, string $path): void {
    expect(scanToJson($path))->toBe(scanToJson($path));
})->with(fn (): array => array_map(
    static fn (string $name, string $path): array => [$name, $path],
    array_keys(deterministicFixtures()),
    array_values(deterministicFixtures()),
));

it('emits byte-identical JSON for the same source at a different path and file creation order', function (string $name, string $path): void {
    $copy = sys_get_temp_dir().'/loom-determinism-'.bin2hex(random_bytes(6));
    copyTreeReversed($path, $copy);

    try {
        expect(scanToJson($copy))->toBe(scanToJson($path));
    } finally {
        removeTree($copy);
    }
})->with(fn (): array => array_map(
    static fn (string $name, string $path): array => [$name, $path],
    array_keys(deterministicFixtures()),
    array_values(deterministicFixtures()),
));

it('yields scanned files in sorted path order', function (): void {
    $root = sys_get_temp_dir().'/loom-order-'.bin2hex(random_bytes(6));
    mkdir("{$root}/app/b", 0777, true);
    mkdir("{$root}/app/a", 0777, true);
    foreach (['app/z.php', 'app/b/y.php', 'app/a/x.php', 'app/m.php'] as $file) {
        file_put_contents("{$root}/{$file}", '<?php');
    }

    try {
        $files = [];
        foreach ((new ScanScope(['app']))->files($root) as $file) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    } finally {
        removeTree($root);
    }

    expect($files)->toBe(['app/a/x.php', 'app/b/y.php', 'app/m.php', 'app/z.php']);
});
