<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\ListenerScanner;

function listenerFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/listener-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function listenerByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('returns an empty array when neither app/Listeners/ nor app/Providers/ exist', function () {
    $entries = (new ListenerScanner)->scan(sys_get_temp_dir())['listeners'];

    expect($entries)->toBe([]);
});

it('discovers the expected set of listeners from the fixture app', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $fqcns = array_column($entries, 'fqcn');

    expect($fqcns)->toContain('App\\Listeners\\SendOrderConfirmation');
    expect($fqcns)->toContain('App\\Listeners\\UpdateInventory');
    expect($fqcns)->toContain('App\\Listeners\\NotifyAdmins');
    expect($fqcns)->toContain('App\\Listeners\\PsrOnly');
    expect($fqcns)->toContain('App\\Listeners\\UntypedListener');
    expect($fqcns)->toContain('App\\Domain\\Invoicing\\Listeners\\IssueInvoice');
    expect($fqcns)->toContain('App\\Listeners\\OrderEventSubscriber');
    expect($fqcns)->toContain('App\\Listeners\\AuditSubscriber');
    expect($fqcns)->toContain('App\\Listeners\\OrderEventsHandler');
    expect($entries)->toHaveCount(9);
});

it('applies listen_array precedence over auto-discovery for SendOrderConfirmation', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\SendOrderConfirmation');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('listen_array');
    expect($entry['queued'])->toBeTrue();
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/SendOrderConfirmation.php');
    expect($entry['line'])->toBe(10);
    expect($entry['dispatches'])->toBe([]);
});

it('records UpdateInventory as auto_discovered and not queued', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\UpdateInventory');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('auto_discovered');
    expect($entry['queued'])->toBeFalse();
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/UpdateInventory.php');
    expect($entry['line'])->toBe(9);
});

it('upgrades NotifyAdmins to event_listen_call precedence over auto-discovery', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\NotifyAdmins');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('event_listen_call');
    expect($entry['queued'])->toBeFalse();
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\StockLow', 'method' => 'handle'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/NotifyAdmins.php');
    expect($entry['line'])->toBe(9);
});

it('locates PsrOnly via the PSR-4 guess and records it as listen_array', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\PsrOnly');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('listen_array');
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'someMethod'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/PsrOnly.php');
    expect($entry['line'])->toBe(7);
    expect($entry['queued'])->toBeFalse();
});

it('records auto-discovered listeners with no resolvable event type as empty handles', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\UntypedListener');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('auto_discovered');
    expect($entry['handles'])->toBe([]);
    expect($entry['queued'])->toBeFalse();
    expect($entry['file'])->toBe('app/Listeners/UntypedListener.php');
    expect($entry['line'])->toBe(7);
});

it('skips dynamic-event and closure listener registrations', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    expect(listenerByFqcn($entries, 'App\\Listeners\\NeverSeen'))->toBeNull();
});

it('ignores $listen arrays on classes that are not EventServiceProvider', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    // OtherProvider is a plain class with a $listen property that references
    // IgnoredListener. ListenArrayVisitor filters by class shape (must be
    // named EventServiceProvider or extend the abstract base), so IgnoredListener
    // is not picked up via this path.
    expect(listenerByFqcn($entries, 'App\\Listeners\\IgnoredListener'))->toBeNull();
});

it('discovers Event::listen() registrations in providers outside app/Providers/', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    // InvoicingServiceProvider lives at app/Domain/Invoicing/InvoicingServiceProvider.php
    // — a DDD-style layout where domain-specific providers live alongside their
    // domain code rather than under app/Providers/. Its boot() calls Event::listen()
    // to register IssueInvoice, which the scanner must pick up.
    $issueInvoice = listenerByFqcn($entries, 'App\\Domain\\Invoicing\\Listeners\\IssueInvoice');

    expect($issueInvoice)->not->toBeNull();
    expect($issueInvoice['registration'])->toBe('event_listen_call');
    expect($issueInvoice['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
    ]);
    expect($issueInvoice['file'])->toBe('app/Domain/Invoicing/Listeners/IssueInvoice.php');
});

it('discovers subscribers from the $subscribe array on EventServiceProvider', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\OrderEventSubscriber');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('subscriber');
    expect($entry['queued'])->toBeTrue();
    // The class also defines handle(OrderPlaced), so auto-discovery adds the
    // {OrderPlaced, handle} tuple to handles[] in addition to the subscriber
    // mappings. Registration stays 'subscriber' (highest precedence).
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handleOrderPlaced'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'handleStockLow'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/OrderEventSubscriber.php');
});

it('discovers subscribers from Event::subscribe() calls', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\AuditSubscriber');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('subscriber');
    expect($entry['queued'])->toBeFalse();
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'audit'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/AuditSubscriber.php');
});

it('emits dispatches as an empty array for every listener', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry['dispatches'])->toBe([]);
    }
});

it('sorts listener entries by FQCN ascending', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $fqcns = array_column($entries, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    foreach ($entries as $entry) {
        expect($entry['file'])->not->toContain('\\');
        expect($entry['file'])->toStartWith('app/');
    }
});

it('emits a single multi-handler listener entry with one handles[] entry per (event, method)', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    // OrderEventsHandler is registered twice via $listen — once for OrderPlaced
    // via handlePlaced(), once for StockLow via handleStockLow(). The scanner
    // must collapse the two registrations into a single listener entry while
    // keeping both (event, method) pairs in handles[].
    $matches = array_values(array_filter(
        $entries,
        fn (array $entry) => ($entry['fqcn'] ?? null) === 'App\\Listeners\\OrderEventsHandler',
    ));

    expect($matches)->toHaveCount(1);
    expect($matches[0]['registration'])->toBe('listen_array');
    expect($matches[0]['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handlePlaced'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'handleStockLow'],
    ]);
    expect($matches[0]['file'])->toBe('app/Listeners/OrderEventsHandler.php');
});

it('keeps both pairs when one listener handles the same event under auto-discovery and subscriber paths', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    // OrderEventSubscriber has handle(OrderPlaced) (auto-discovered path) AND
    // a subscribe() return-array entry OrderPlaced => 'handleOrderPlaced'. The
    // dedupe key is (event, method), so both pairs survive on the same entry.
    $entry = listenerByFqcn($entries, 'App\\Listeners\\OrderEventSubscriber');

    expect($entry)->not->toBeNull();
    expect($entry['handles'])->toContain(
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
    );
    expect($entry['handles'])->toContain(
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handleOrderPlaced'],
    );
});

it('emits the schema-required keys on every listener', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['fqcn', 'file', 'line', 'handles', 'registration', 'queued', 'dispatches']);
    }
});
