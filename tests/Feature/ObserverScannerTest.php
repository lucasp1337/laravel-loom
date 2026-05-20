<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\ModelEventEntry;
use Lucasp\Loom\Dto\ObserverEntry;
use Lucasp\Loom\Scanners\ObserverScanner;

function observerFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/observer-fixture-app';
}

/**
 * @param  list<ObserverEntry>  $entries
 */
function observerByPair(array $entries, string $fqcn, string $observes): ?ObserverEntry
{
    foreach ($entries as $entry) {
        if ($entry->fqcn === $fqcn && $entry->observes === $observes) {
            return $entry;
        }
    }

    return null;
}

/**
 * @param  list<ModelEventEntry>  $entries
 */
function modelEventById(array $entries, string $id): ?ModelEventEntry
{
    foreach ($entries as $entry) {
        if ($entry->id === $id) {
            return $entry;
        }
    }

    return null;
}

it('returns both observers and model_events keys, even when the app/ dir is missing', function () {
    $result = (new ObserverScanner)->scan(sys_get_temp_dir().'/__no_such_dir_'.uniqid());

    expect($result)->toHaveKeys(['observers', 'model_events']);
    expect($result['observers'])->toBe([]);
    expect($result['model_events'])->toBe([]);
});

describe('ObserverScanner against observer-fixture-app', function () {
    it('returns a section-keyed array containing observers and model_events', function () {
        $result = (new ObserverScanner)->scan(observerFixturePath());

        expect($result)->toHaveKeys(['observers', 'model_events']);
        expect($result['observers'])->toBeArray();
        expect($result['model_events'])->toBeArray();
    });

    it('discovers exactly four observer entries', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        expect($observers)->toHaveCount(4);

        $pairs = array_map(
            fn (ObserverEntry $e): array => [$e->fqcn, $e->observes],
            $observers,
        );

        expect($pairs)->toContain(['App\\Observers\\UserObserver', 'App\\Models\\User']);
        expect($pairs)->toContain(['App\\Observers\\PostObserver', 'App\\Models\\Post']);
        expect($pairs)->toContain(['App\\Observers\\CommentObserver', 'App\\Models\\Comment']);
        expect($pairs)->toContain(['App\\Observers\\DualObserver', 'App\\Models\\Widget']);
    });

    it('does not include InvoiceHandler in observers (path C only contributes to model_events)', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $fqcns = array_map(fn (ObserverEntry $e): string => $e->fqcn, $observers);
        expect($fqcns)->not->toContain('App\\Handlers\\InvoiceHandler');
    });

    it('records the UserObserver registration as attribute with the expected hooks', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $entry = observerByPair($observers, 'App\\Observers\\UserObserver', 'App\\Models\\User');

        expect($entry)->not->toBeNull();
        expect($entry->registration)->toBe('attribute');
        expect($entry->hooks)->toBe(['creating', 'deleted', 'updated']);
        expect($entry->file)->toBe('app/Observers/UserObserver.php');
        expect($entry->line)->toBe(7);
    });

    it('records the PostObserver registration as observe_call (visibility-agnostic hooks)', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $entry = observerByPair($observers, 'App\\Observers\\PostObserver', 'App\\Models\\Post');

        expect($entry)->not->toBeNull();
        expect($entry->registration)->toBe('observe_call');
        expect($entry->hooks)->toBe(['creating', 'saved']);
        expect($entry->file)->toBe('app/Observers/PostObserver.php');
        expect($entry->line)->toBe(7);
    });

    it('records the CommentObserver as observe_call', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $entry = observerByPair($observers, 'App\\Observers\\CommentObserver', 'App\\Models\\Comment');

        expect($entry)->not->toBeNull();
        expect($entry->registration)->toBe('observe_call');
        expect($entry->hooks)->toBe(['deleting', 'restored']);
        expect($entry->file)->toBe('app/Observers/CommentObserver.php');
        expect($entry->line)->toBe(7);
    });

    it('prefers attribute over observe_call for the DualObserver/Widget pair', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $entry = observerByPair($observers, 'App\\Observers\\DualObserver', 'App\\Models\\Widget');

        expect($entry)->not->toBeNull();
        expect($entry->registration)->toBe('attribute');
        expect($entry->hooks)->toBe(['created', 'updated']);
        expect($entry->file)->toBe('app/Observers/DualObserver.php');
        expect($entry->line)->toBe(7);
    });

    it('sorts observer entries by fqcn then observes ascending', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        $keys = array_map(
            fn (ObserverEntry $e): string => $e->fqcn.'|'.$e->observes,
            $observers,
        );

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        expect($keys)->toBe($sorted);
    });

    it('reports observer file paths as relative + forward slashes', function () {
        $observers = (new ObserverScanner)->scan(observerFixturePath())['observers'];

        foreach ($observers as $entry) {
            expect($entry->file)->not->toContain('\\');
            expect($entry->file)->toStartWith('app/Observers/');
        }
    });
});

describe('ObserverScanner model_events output', function () {
    it('emits exactly the expected number of model_event entries', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        expect($modelEvents)->toHaveCount(10);
    });

    it('uses the "eloquent.{hook}: {Model}" id format', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        foreach ($modelEvents as $entry) {
            expect($entry->id)->toMatch('/^eloquent\.[a-zA-Z]+: App\\\\Models\\\\/');
        }
    });

    it('emits valid canonical hook names', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        $valid = [
            'retrieved', 'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
            'replicating', 'trashed', 'forceDeleting', 'forceDeleted',
            'booting', 'booted',
        ];
        foreach ($modelEvents as $entry) {
            expect($valid)->toContain($entry->event);
        }
    });

    it('records the path C handler for Product::deleted', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        $entry = modelEventById($modelEvents, 'eloquent.deleted: App\\Models\\Product');

        expect($entry)->not->toBeNull();
        expect($entry->model)->toBe('App\\Models\\Product');
        expect($entry->event)->toBe('deleted');
        expect($entry->handledBy)->toBe(['App\\Handlers\\InvoiceHandler::deleted']);
    });

    it('dedupes (User, creating) from observer hook + path C listen string', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        $entry = modelEventById($modelEvents, 'eloquent.creating: App\\Models\\User');

        expect($entry)->not->toBeNull();
        expect($entry->handledBy)->toBe(['App\\Observers\\UserObserver::creating']);
    });

    it('sorts handled_by entries inside each model_event', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        foreach ($modelEvents as $entry) {
            $sorted = $entry->handledBy;
            sort($sorted, SORT_STRING);
            expect($entry->handledBy)->toBe($sorted);
        }
    });

    it('sorts the model_events list by id ascending', function () {
        $modelEvents = (new ObserverScanner)->scan(observerFixturePath())['model_events'];

        $ids = array_map(fn (ModelEventEntry $e): string => $e->id, $modelEvents);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        expect($ids)->toBe($sorted);
    });
});
