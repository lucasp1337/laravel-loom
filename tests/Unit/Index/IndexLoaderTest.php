<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\IndexLoadException;

/**
 * The minimal valid envelope: only the three required meta fields, no sections.
 *
 * @return array<string, string>
 */
function minimalIndexArray(): array
{
    return [
        'loom_version' => '0.3.0',
        'scanned_at' => '2026-06-07T12:00:00+00:00',
        'laravel_version' => '12.x',
    ];
}

it('hydrates the meta envelope from an array', function () {
    $index = (new IndexLoader)->fromArray(minimalIndexArray());

    expect($index)->toBeInstanceOf(Index::class);
    expect($index->loomVersion)->toBe('0.3.0');
    expect($index->scannedAt)->toBe('2026-06-07T12:00:00+00:00');
    expect($index->laravelVersion)->toBe('12.x');
});

it('defaults every section to an empty list when absent', function () {
    $index = (new IndexLoader)->fromArray(minimalIndexArray());

    expect($index->events())->toBe([]);
    expect($index->modelEvents())->toBe([]);
    expect($index->listeners())->toBe([]);
    expect($index->closureListeners())->toBe([]);
    expect($index->observers())->toBe([]);
    expect($index->jobs())->toBe([]);
    expect($index->mailables())->toBe([]);
    expect($index->notifications())->toBe([]);
    expect($index->scheduled())->toBe([]);
    expect($index->routes())->toBe([]);
    expect($index->unresolvedDispatches())->toBe([]);
});

it('returns null and empty lookups for an empty index', function () {
    $index = (new IndexLoader)->fromArray(minimalIndexArray());

    expect($index->findEvent('App\\Events\\Anything'))->toBeNull();
    expect($index->findListener('App\\Listeners\\Anything'))->toBeNull();
    expect($index->findObserver('App\\Observers\\Anything'))->toBeNull();
    expect($index->findJob('App\\Jobs\\Anything'))->toBeNull();
    expect($index->findMailable('App\\Mail\\Anything'))->toBeNull();
    expect($index->findNotification('App\\Notifications\\Anything'))->toBeNull();
    expect($index->dispatchersOf('App\\Events\\Anything'))->toBe([]);
    expect($index->handlersOf('App\\Events\\Anything'))->toBe([]);
});

it('produces identical typed output from fromJson and fromArray for the same payload', function () {
    $payload = representativeIndexArray();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    $fromArray = (new IndexLoader)->fromArray($payload);
    $fromJson = (new IndexLoader)->fromJson($json);

    expect($fromJson->toArray())->toBe($fromArray->toArray());
    expect($fromJson->events()[0]->fqcn)->toBe($fromArray->events()[0]->fqcn);
    expect($fromJson->jobs()[0]->queueConfig?->connection)
        ->toBe($fromArray->jobs()[0]->queueConfig?->connection);
});

it('loads an index from a file on disk', function () {
    $path = tempnam(sys_get_temp_dir(), 'loom-index-');
    expect($path)->toBeString();

    try {
        file_put_contents($path, json_encode(representativeIndexArray(), JSON_THROW_ON_ERROR));

        $index = (new IndexLoader)->fromFile($path);

        expect($index->loomVersion)->toBe('0.3.0');
        expect($index->events())->toHaveCount(1);
        expect($index->events()[0]->fqcn)->toBe('App\\Events\\OrderShipped');
    } finally {
        @unlink($path);
    }
});

it('throws when the file cannot be read', function () {
    (new IndexLoader)->fromFile('/nonexistent/path/to/loom-index.json');
})->throws(IndexLoadException::class);

it('throws on invalid JSON', function () {
    (new IndexLoader)->fromJson('{not valid json');
})->throws(IndexLoadException::class);

it('throws when the JSON decodes to a scalar instead of an object', function () {
    (new IndexLoader)->fromJson('"hello"');
})->throws(IndexLoadException::class);

it('throws when a JSON array is given (decodes to a list, then fails missing meta)', function () {
    (new IndexLoader)->fromJson('[1,2]');
})->throws(IndexLoadException::class);

it('throws when a required meta field is missing', function () {
    (new IndexLoader)->fromArray([
        'scanned_at' => '2026-06-07T12:00:00+00:00',
        'laravel_version' => '12.x',
    ]);
})->throws(IndexLoadException::class, 'loom_version');

it('throws when scanned_at is missing', function () {
    (new IndexLoader)->fromArray([
        'loom_version' => '0.3.0',
        'laravel_version' => '12.x',
    ]);
})->throws(IndexLoadException::class, 'scanned_at');

it('throws when laravel_version is missing', function () {
    (new IndexLoader)->fromArray([
        'loom_version' => '0.3.0',
        'scanned_at' => '2026-06-07T12:00:00+00:00',
    ]);
})->throws(IndexLoadException::class, 'laravel_version');

it('memoizes hydrated sections so repeated getter calls return the same instances', function () {
    $index = (new IndexLoader)->fromArray(representativeIndexArray());

    expect($index->events()[0])->toBe($index->events()[0]);
});
