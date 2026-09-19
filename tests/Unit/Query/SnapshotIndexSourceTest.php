<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\SnapshotIndexSource;
use Lucasp\Loom\Query\IndexUnavailableException;

function snapshotFile(string $scannedAt): string
{
    $data = require __DIR__.'/../../Fixtures/query-index.php';
    $data['scanned_at'] = $scannedAt;
    $path = tempnam(sys_get_temp_dir(), 'loom-snap-').'.json';
    file_put_contents($path, json_encode($data));

    return $path;
}

it('loads the snapshot and reports availability', function () {
    $source = new SnapshotIndexSource(new IndexLoader, snapshotFile('2026-01-01T00:00:00+00:00'));

    expect($source->isAvailable())->toBeTrue()
        ->and($source->index()->scannedAt)->toBe('2026-01-01T00:00:00+00:00')
        ->and($source->index())->toBe($source->index());
});

it('reloads when the file changes on disk', function () {
    $path = snapshotFile('one');
    $source = new SnapshotIndexSource(new IndexLoader, $path);
    expect($source->index()->scannedAt)->toBe('one');

    $data = json_decode((string) file_get_contents($path), true);
    $data['scanned_at'] = 'two';
    file_put_contents($path, json_encode($data));
    touch($path, time() + 10);
    clearstatcache();

    expect($source->index()->scannedAt)->toBe('two');
});

it('reports a missing snapshot without scanning', function () {
    $source = new SnapshotIndexSource(new IndexLoader, sys_get_temp_dir().'/loom-does-not-exist.json');

    expect($source->isAvailable())->toBeFalse();
    $source->index();
})->throws(IndexUnavailableException::class, 'Run `php artisan loom:scan` first.');

it('wraps an unparseable snapshot', function () {
    $path = tempnam(sys_get_temp_dir(), 'loom-bad-').'.json';
    file_put_contents($path, '{not json');

    (new SnapshotIndexSource(new IndexLoader, $path))->index();
})->throws(IndexUnavailableException::class, 'could not be loaded');
