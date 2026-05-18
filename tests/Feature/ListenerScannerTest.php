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
    expect($fqcns)->toContain('App\\Listeners\\ImperativeSubscriber');
    expect($fqcns)->toContain('App\\Listeners\\InheritsQueued');
    expect($entries)->toHaveCount(11);
});

it('detects indirect ShouldQueue via a parent abstract class', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\InheritsQueued');

    expect($entry)->not->toBeNull();
    // InheritsQueued does NOT implement ShouldQueue directly — it extends
    // AbstractQueuedListener which does. The resolver-driven queued
    // detection should walk the extends chain and pick that up.
    expect($entry['queued'])->toBeTrue();
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

it('upgrades NotifyAdmins to subscriber precedence when imperative subscribe() registers it', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\NotifyAdmins');

    expect($entry)->not->toBeNull();
    // ImperativeSubscriber::subscribe() calls `$events->listen(InventoryDepleted::class,
    // [NotifyAdmins::class, 'handle'])`, which is a foreign listener registration at
    // 'subscriber' precedence. That outranks the Event::listen() registration in
    // EventServiceProvider::boot(), so the final registration must be 'subscriber'.
    expect($entry['registration'])->toBe('subscriber');
    expect($entry['queued'])->toBeFalse();
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\InventoryDepleted', 'method' => 'handle'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'handle'],
    ]);
    expect($entry['file'])->toBe('app/Listeners/NotifyAdmins.php');
    expect($entry['line'])->toBe(9);
});

it('records the imperative subscriber with its self-bound and bare-string handles', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    $entry = listenerByFqcn($entries, 'App\\Listeners\\ImperativeSubscriber');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('subscriber');
    expect($entry['queued'])->toBeFalse();
    expect($entry['file'])->toBe('app/Listeners/ImperativeSubscriber.php');
    // OrderPlaced::onPlaced is a [self::class, 'onPlaced'] tuple; StockLow::onStockLow
    // is a bare-string method that Laravel binds to the subscriber instance.
    expect($entry['handles'])->toBe([
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
        ['event' => 'App\\Events\\StockLow', 'method' => 'onStockLow'],
    ]);
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

it('discovers all closure listeners across $listen, Event::listen, and subscriber sources', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    expect($closures)->toHaveCount(5);
});

it('sorts closure_listeners by (event, file, line) ascending', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    expect($closures)->toBe([
        [
            'event' => 'App\\Events\\InventoryDepleted',
            'file' => 'app/Listeners/OrderEventSubscriber.php',
            'line' => 22,
            'registration' => 'subscriber',
            'queued' => false,
            'dispatches' => [],
        ],
        [
            'event' => 'App\\Events\\OrderPlaced',
            'file' => 'app/Listeners/ImperativeSubscriber.php',
            'line' => 18,
            'registration' => 'subscriber',
            'queued' => false,
            'dispatches' => [],
        ],
        [
            'event' => 'App\\Events\\OrderPlaced',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 25,
            'registration' => 'listen_array',
            'queued' => false,
            'dispatches' => [],
        ],
        [
            'event' => 'App\\Events\\OrderPlaced',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 40,
            'registration' => 'event_listen_call',
            'queued' => false,
            'dispatches' => [],
        ],
        [
            'event' => 'App\\Events\\StockLow',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 41,
            'registration' => 'event_listen_call',
            'queued' => false,
            'dispatches' => [],
        ],
    ]);
});

it('emits every closure listener with queued=false and dispatches=[]', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    expect($closures)->not->toBe([]);

    foreach ($closures as $entry) {
        expect($entry['queued'])->toBeFalse();
        expect($entry['dispatches'])->toBe([]);
    }
});

it('attributes registration correctly per closure listener source', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    $byRegistration = [];
    foreach ($closures as $entry) {
        $byRegistration[$entry['registration']] = ($byRegistration[$entry['registration']] ?? 0) + 1;
    }

    expect($byRegistration)->toBe([
        'subscriber' => 2,
        'listen_array' => 1,
        'event_listen_call' => 2,
    ]);
});

it('does not leak closure listeners into the regular listeners[] array', function () {
    $result = (new ListenerScanner)->scan(listenerFixturePath());

    // The fixture has been extended with closure registrations but the regular
    // listeners[] count must stay at 11 (the existing class-based listeners,
    // ImperativeSubscriber, and InheritsQueued).
    expect($result['listeners'])->toHaveCount(11);

    // OrderEventSubscriber's handles[] must not pick up InventoryDepleted —
    // that mapping went to closure_listeners, not handles[].
    $entry = listenerByFqcn($result['listeners'], 'App\\Listeners\\OrderEventSubscriber');
    expect($entry)->not->toBeNull();

    $handledEvents = array_column($entry['handles'], 'event');
    expect($handledEvents)->not->toContain('App\\Events\\InventoryDepleted');
});

it('emits the schema-required keys on every closure listener', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    expect($closures)->not->toBe([]);

    foreach ($closures as $entry) {
        expect($entry)->toHaveKeys(['event', 'file', 'line', 'registration', 'queued', 'dispatches']);
    }
});

it('upgrades a foreign listener to subscriber registration when registered imperatively from subscribe()', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    // Regression guard for the precedence change introduced with imperative
    // subscribe() body discovery. NotifyAdmins is now registered from inside
    // ImperativeSubscriber::subscribe() via a foreign-class tuple. The pair
    // flows through ListenerScanner::applyPair() with REGISTRATION_SUBSCRIBER
    // (precedence 4), which outranks both event_listen_call (2) and
    // auto_discovered (1) — so the final registration must be 'subscriber'.
    $entry = listenerByFqcn($entries, 'App\\Listeners\\NotifyAdmins');

    expect($entry)->not->toBeNull();
    expect($entry['registration'])->toBe('subscriber');
    expect($entry['handles'])->toContain(
        ['event' => 'App\\Events\\InventoryDepleted', 'method' => 'handle'],
    );
});

it('emits a closure_listeners[] entry with registration=subscriber for closures registered from inside subscribe()', function () {
    $closures = (new ListenerScanner)->scan(listenerFixturePath())['closure_listeners'];

    $imperativeClosures = array_values(array_filter(
        $closures,
        fn (array $entry) => $entry['file'] === 'app/Listeners/ImperativeSubscriber.php',
    ));

    expect($imperativeClosures)->toHaveCount(1);
    expect($imperativeClosures[0]['event'])->toBe('App\\Events\\OrderPlaced');
    expect($imperativeClosures[0]['registration'])->toBe('subscriber');
    expect($imperativeClosures[0]['queued'])->toBeFalse();
});

it('emits the schema-required keys on every listener', function () {
    $entries = (new ListenerScanner)->scan(listenerFixturePath())['listeners'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['fqcn', 'file', 'line', 'handles', 'registration', 'queued', 'dispatches']);
    }
});
