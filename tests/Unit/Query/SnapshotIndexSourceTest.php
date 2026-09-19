<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Query\IndexUnavailableException;
use Lucasp\Loom\Query\SnapshotIndexSource;

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

it('sees two same-second writes of different size', function () {
    $path = snapshotFile('one');
    $mtime = filemtime($path);
    $source = new SnapshotIndexSource(new IndexLoader, $path);
    expect($source->index()->scannedAt)->toBe('one');

    $data = json_decode((string) file_get_contents($path), true);
    $data['scanned_at'] = 'a-much-longer-value';
    file_put_contents($path, json_encode($data));
    touch($path, $mtime);

    expect($source->index()->scannedAt)->toBe('a-much-longer-value');
});

it('keeps serving the last good index when a reload fails', function () {
    $path = snapshotFile('good');
    $source = new SnapshotIndexSource(new IndexLoader, $path);
    $good = $source->index();

    file_put_contents($path, '{"half":');

    expect($source->index())->toBe($good)
        ->and($source->payload()['scanned_at'])->toBe('good');
});
