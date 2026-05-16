<?php

declare(strict_types=1);

use Lucasp\Atlas\Scanners\EventScanner;

function eventFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/event-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function entryByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
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
    // Notifier.php contains event($eventClass) and event("App\\Events\\Inventory{$suffix}").
    // Neither dispatches a statically-resolvable target, so no extra entries appear.
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $fqcns = array_column($entries, 'fqcn');

    // The three app/Events/ classes plus the helper-form CustomEvent are
    // present — no spurious dynamic entries.
    expect($fqcns)->toContain('App\\Events\\OrderPlaced');
    expect($fqcns)->toContain('App\\Events\\Nested\\InventoryAdjusted');
    expect($fqcns)->toContain('App\\Events\\AbstractDomainEvent');
    expect($fqcns)->toContain('App\\Outside\\CustomEvent');
    expect($fqcns)->toHaveCount(4);
});

it('trims X::dispatch() Dispatchable candidates whose file is outside app/Events/', function () {
    // Checkout.php contains SendReceipt::dispatch(); the resolved file lives
    // under app/Jobs/, not app/Events/. Because every hit for SendReceipt is
    // the Dispatchable static-call form, the §3b trim removes the candidate.
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect(entryByFqcn($entries, 'App\\Jobs\\SendReceipt'))->toBeNull();
});

it('includes helper-form dispatches whose target lives outside app/Events/', function () {
    // Checkout.php contains event(new CustomEvent(...)). The helper form is
    // an unambiguous event dispatch and bypasses the app/Events/ trim, so
    // CustomEvent IS included even though its file lives under app/Outside/.
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $custom = entryByFqcn($entries, 'App\\Outside\\CustomEvent');

    expect($custom)->not->toBeNull();
    expect($custom['file'])->toBe('app/Outside/CustomEvent.php');
    expect($custom['line'])->toBe(7);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $orderPlaced = entryByFqcn($entries, 'App\\Events\\OrderPlaced');
    $nested = entryByFqcn($entries, 'App\\Events\\Nested\\InventoryAdjusted');
    $abstract = entryByFqcn($entries, 'App\\Events\\AbstractDomainEvent');

    expect($orderPlaced['file'])->toBe('app/Events/OrderPlaced.php');
    expect($nested['file'])->toBe('app/Events/Nested/InventoryAdjusted.php');
    expect($abstract['file'])->toBe('app/Events/AbstractDomainEvent.php');

    foreach ($entries as $entry) {
        expect($entry['file'])->not->toContain('\\');
    }
});

it('reports the class declaration line for every entry', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    // Lines must match the actual `class` keyword location in each fixture file.
    expect(entryByFqcn($entries, 'App\\Events\\OrderPlaced')['line'])->toBe(7);
    expect(entryByFqcn($entries, 'App\\Events\\Nested\\InventoryAdjusted')['line'])->toBe(7);
    expect(entryByFqcn($entries, 'App\\Events\\AbstractDomainEvent')['line'])->toBe(7);
});

it('emits schema-shaped entries with empty dispatched_from and handled_by', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['id', 'fqcn', 'kind', 'file', 'line', 'dispatched_from', 'handled_by']);
        expect($entry['kind'])->toBe('class');
        expect($entry['id'])->toBe($entry['fqcn']);
        expect($entry['dispatched_from'])->toBe([]);
        expect($entry['handled_by'])->toBe([]);
    }
});

it('sorts entries by FQCN ascending', function () {
    $entries = (new EventScanner)->scan(eventFixturePath())['events'];

    $fqcns = array_column($entries, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('returns an empty array when the app/ directory is missing', function () {
    $entries = (new EventScanner)->scan(sys_get_temp_dir())['events'];

    expect($entries)->toBe([]);
});
