<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\EventEntry;
use Lucasp\Loom\Scanners\EventScanner;

function eventFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/event-fixture-app';
}

/**
 * @param  list<EventEntry>  $entries
 */
function entryByFqcn(array $entries, string $fqcn): ?EventEntry
{
    foreach ($entries as $entry) {
        if ($entry->fqcn === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('discovers event classes from the filesystem walk of app/Events/', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Events\\OrderPlaced'))->not->toBeNull();
    expect(entryByFqcn($entries, 'App\\Events\\Nested\\InventoryAdjusted'))->not->toBeNull();
    expect(entryByFqcn($entries, 'App\\Events\\AbstractDomainEvent'))->not->toBeNull();
});

it('does not include classes that are not events', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Models\\User'))->toBeNull();
    expect(entryByFqcn($entries, 'App\\Services\\Checkout'))->toBeNull();
    expect(entryByFqcn($entries, 'App\\Services\\Notifier'))->toBeNull();
});

it('silently ignores dynamic and string-interpolated event() calls', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $fqcns = array_map(fn (EventEntry $e): string => $e->fqcn, $entries);

    expect($fqcns)->toContain('App\\Events\\OrderPlaced');
    expect($fqcns)->toContain('App\\Events\\Nested\\InventoryAdjusted');
    expect($fqcns)->toContain('App\\Events\\AbstractDomainEvent');
    expect($fqcns)->toContain('App\\Outside\\CustomEvent');
    expect($fqcns)->toContain('App\\Outside\\BroadcastOnly');
    expect($fqcns)->toHaveCount(5);
});

it('trims X::dispatch() Dispatchable candidates whose file is outside app/Events/', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Jobs\\SendReceipt'))->toBeNull();
});

it('includes helper-form dispatches whose target lives outside app/Events/', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $custom = entryByFqcn($entries, 'App\\Outside\\CustomEvent');

    expect($custom)->not->toBeNull();
    expect($custom->file)->toBe('app/Outside/CustomEvent.php');
    expect($custom->line)->toBe(7);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Events\\OrderPlaced')->file)->toBe('app/Events/OrderPlaced.php');
    expect(entryByFqcn($entries, 'App\\Events\\Nested\\InventoryAdjusted')->file)->toBe('app/Events/Nested/InventoryAdjusted.php');
    expect(entryByFqcn($entries, 'App\\Events\\AbstractDomainEvent')->file)->toBe('app/Events/AbstractDomainEvent.php');

    foreach ($entries as $entry) {
        expect($entry->file)->not->toContain('\\');
    }
});

it('reports the class declaration line for every entry', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Events\\OrderPlaced')->line)->toBe(7);
    expect(entryByFqcn($entries, 'App\\Events\\Nested\\InventoryAdjusted')->line)->toBe(7);
    expect(entryByFqcn($entries, 'App\\Events\\AbstractDomainEvent')->line)->toBe(7);
});

it('emits each entry as an EventEntry DTO with id equal to fqcn', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(EventEntry::class);
        expect($entry->id)->toBe($entry->fqcn);
    }
});

it('sorts entries by FQCN ascending', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $fqcns = array_map(fn (EventEntry $e): string => $e->fqcn, $entries);
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('returns an empty array when the app/ directory is missing', function () {
    $entries = (new EventScanner)->scan(sys_get_temp_dir())['events'];

    expect($entries)->toBe([]);
});
