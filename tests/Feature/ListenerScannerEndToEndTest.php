<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\ListenerScanner;

function listenerEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/listener-fixture-app';
}

it('produces a schema-valid index when only ListenerScanner is registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('counts discovered listeners in stats.listeners', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    // SendOrderConfirmation, UpdateInventory, NotifyAdmins, PsrOnly, UntypedListener,
    // IssueInvoice (registered via Event::listen() in InvoicingServiceProvider, which
    // sits at app/Domain/Invoicing/ rather than app/Providers/), OrderEventSubscriber
    // ($subscribe array), AuditSubscriber (Event::subscribe()), and OrderEventsHandler
    // (multi-handler listener registered via the $listen array with explicit methods).
    // NeverSeen is skipped (dynamic event); IgnoredListener is skipped (its $listen
    // array sits on a non-EventServiceProvider class, filtered by ListenArrayVisitor).
    // ImperativeSubscriber is registered via Event::subscribe() and its imperative
    // subscribe() body matches the 10th listener entry.
    expect($payload['stats']['listeners'])->toBe(10);
});

it('includes the known listener FQCNs', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    $fqcns = array_column($payload['listeners'], 'fqcn');

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
});

it('leaves non-listener sections as empty arrays, not null', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['events'])->toBe([]);
    expect($payload['observers'])->toBe([]);
    expect($payload['model_events'])->toBe([]);
    expect($payload['unresolved_dispatches'])->toBe([]);

    expect($payload['stats']['events'])->toBe(0);
    expect($payload['stats']['observers'])->toBe(0);
    expect($payload['stats']['unresolved_dispatches'])->toBe(0);
});

it('sorts events[*].handled_by by listener asc then method asc', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    $orderPlaced = null;
    foreach ($payload['events'] as $event) {
        if (($event['fqcn'] ?? null) === 'App\\Events\\OrderPlaced') {
            $orderPlaced = $event;
            break;
        }
    }

    expect($orderPlaced)->not->toBeNull();

    // OrderPlaced is handled by:
    //   - AuditSubscriber::audit                              (subscriber)
    //   - IssueInvoice::handle                                (event_listen_call)
    //   - OrderEventSubscriber::handle                        (auto-discovered handle())
    //   - OrderEventSubscriber::handleOrderPlaced             (subscribe array)
    //   - OrderEventsHandler::handlePlaced                    ($listen array tuple)
    //   - PsrOnly::someMethod                                 ($listen array tuple)
    //   - SendOrderConfirmation::handle                       ($listen array bare class)
    //   - UpdateInventory::handle                             (auto-discovered)
    // Sorted by (listener, method) ascending.
    expect($orderPlaced['handled_by'])->toBe([
        ['listener' => 'App\\Domain\\Invoicing\\Listeners\\IssueInvoice', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\AuditSubscriber', 'method' => 'audit'],
        ['listener' => 'App\\Listeners\\ImperativeSubscriber', 'method' => 'onPlaced'],
        ['listener' => 'App\\Listeners\\OrderEventSubscriber', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\OrderEventSubscriber', 'method' => 'handleOrderPlaced'],
        ['listener' => 'App\\Listeners\\OrderEventsHandler', 'method' => 'handlePlaced'],
        ['listener' => 'App\\Listeners\\PsrOnly', 'method' => 'someMethod'],
        ['listener' => 'App\\Listeners\\SendOrderConfirmation', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\UpdateInventory', 'method' => 'handle'],
    ]);
});

it('counts closure listeners in stats.closure_listeners', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['stats']['closure_listeners'])->toBe(5);
    expect($payload['closure_listeners'])->toHaveCount(5);
});

it('produces a schema-valid index with closure listeners populated', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('passes through closure listener entries from the scanner into the index in sorted order', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    $events = array_column($payload['closure_listeners'], 'event');
    expect($events)->toBe([
        'App\\Events\\InventoryDepleted',
        'App\\Events\\OrderPlaced',
        'App\\Events\\OrderPlaced',
        'App\\Events\\OrderPlaced',
        'App\\Events\\StockLow',
    ]);
});

it('emits every listener with an empty dispatches array', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    foreach ($payload['listeners'] as $entry) {
        expect($entry['dispatches'])->toBe([]);
    }
});
