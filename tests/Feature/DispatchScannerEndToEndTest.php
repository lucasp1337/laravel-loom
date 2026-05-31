<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\ObserverScanner;

function dispatchEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/dispatch-fixture-app';
}

function buildDispatchEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new ObserverScanner);
    $builder->register(new DispatchScanner);

    return $builder->build(dispatchEndToEndFixturePath(), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function dispatchEntryByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('produces a schema-valid index with all four scanners registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new ObserverScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(dispatchEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('does not expose _dispatch_sites in the final payload', function () {
    $payload = buildDispatchEndToEndPayload();

    expect($payload)->not->toHaveKey('_dispatch_sites');
});

it('populates events[*].handled_by from listener handles inversion', function () {
    $payload = buildDispatchEndToEndPayload();

    $orderPlaced = dispatchEntryByFqcn($payload['events'], 'App\\Events\\OrderPlaced');
    expect($orderPlaced)->not->toBeNull();
    expect($orderPlaced['handled_by'])->toContain([
        'listener' => 'App\\Listeners\\SendOrderConfirmation',
        'method' => 'handle',
    ]);
});

it('populates listeners[*].dispatches from the listener handle() body', function () {
    $payload = buildDispatchEndToEndPayload();

    $listener = dispatchEntryByFqcn($payload['listeners'], 'App\\Listeners\\SendOrderConfirmation');
    expect($listener)->not->toBeNull();
    expect($listener['dispatches'])->toHaveCount(3);

    $byTarget = [];
    foreach ($listener['dispatches'] as $d) {
        $byTarget[$d['target']] = $d;
    }

    expect($byTarget)->toHaveKey('App\\Events\\OrderConfirmationSent');
    expect($byTarget['App\\Events\\OrderConfirmationSent']['kind'])->toBe('event');
    expect($byTarget['App\\Events\\OrderConfirmationSent']['confidence'])->toBe('high');

    expect($byTarget)->toHaveKey('App\\Events\\InventoryAdjusted');
    expect($byTarget['App\\Events\\InventoryAdjusted']['kind'])->toBe('event');

    // Dispatchable form disambiguated to job because SendReceipt is not in events[].
    expect($byTarget)->toHaveKey('App\\Jobs\\SendReceipt');
    expect($byTarget['App\\Jobs\\SendReceipt']['kind'])->toBe('job');
});

it('excludes dispatches from listener methods that are not handle()', function () {
    $payload = buildDispatchEndToEndPayload();

    $listener = dispatchEntryByFqcn($payload['listeners'], 'App\\Listeners\\SendOrderConfirmation');
    expect($listener)->not->toBeNull();

    foreach ($listener['dispatches'] as $d) {
        // line 29 is the dispatch inside otherMethod() — must not be attributed.
        expect($d['line'])->not->toBe(29);
        expect($d['target'])->not->toBe('App\\Events\\SomeEvent');
    }
});

it('populates observers[*].dispatches from canonical hook methods only', function () {
    $payload = buildDispatchEndToEndPayload();

    $observer = dispatchEntryByFqcn($payload['observers'], 'App\\Observers\\UserObserver');
    expect($observer)->not->toBeNull();
    expect($observer['dispatches'])->toHaveCount(1);

    $dispatch = $observer['dispatches'][0];
    expect($dispatch['target'])->toBe('App\\Jobs\\SendReceipt');
    expect($dispatch['kind'])->toBe('job');
    expect($dispatch['file'])->toBe('app/Observers/UserObserver.php');
    expect($dispatch['line'])->toBe(15);
});

it('excludes dispatches from observer methods that are not in the hook enum', function () {
    $payload = buildDispatchEndToEndPayload();

    $observer = dispatchEntryByFqcn($payload['observers'], 'App\\Observers\\UserObserver');
    expect($observer)->not->toBeNull();

    foreach ($observer['dispatches'] as $d) {
        // line 20 is the dispatch inside utility() — not a hook method.
        expect($d['line'])->not->toBe(20);
    }
});

it('populates events[OrderPlaced].dispatched_from from arbitrary callers', function () {
    $payload = buildDispatchEndToEndPayload();

    $orderPlaced = dispatchEntryByFqcn($payload['events'], 'App\\Events\\OrderPlaced');
    expect($orderPlaced)->not->toBeNull();
    expect($orderPlaced['dispatched_from'])->toHaveCount(1);

    $entry = $orderPlaced['dispatched_from'][0];
    expect($entry['file'])->toBe('app/Services/Checkout.php');
    expect($entry['line'])->toBe(14);
    expect($entry['method'])->toBe('App\\Services\\Checkout::finalize');
});

it('populates events[InventoryAdjusted].dispatched_from from the listener', function () {
    $payload = buildDispatchEndToEndPayload();

    $event = dispatchEntryByFqcn($payload['events'], 'App\\Events\\InventoryAdjusted');
    expect($event)->not->toBeNull();
    expect($event['dispatched_from'])->toHaveCount(1);
    expect($event['dispatched_from'][0]['method'])
        ->toBe('App\\Listeners\\SendOrderConfirmation::handle');
});

it('records the unresolved_dispatches with file, line, expression, reason', function () {
    $payload = buildDispatchEndToEndPayload();

    expect($payload['unresolved_dispatches'])->toHaveCount(2);

    foreach ($payload['unresolved_dispatches'] as $entry) {
        expect($entry)->toHaveKeys(['file', 'line', 'expression', 'reason']);
        expect($entry['file'])->toBe('app/Services/Checkout.php');
    }
});

it('attributes dispatches in non-handle() methods listed in handles[*].method', function () {
    // Regression guard for the v0.2 cross-link Phase 3 fix: under the old
    // `method === 'handle'` join, dispatches inside MultiHandlerListener's
    // handlePlaced() and handleAdjusted() bodies were dropped. The new join
    // is `method ∈ listener.handles[*].method`.
    $payload = buildDispatchEndToEndPayload();

    $listener = dispatchEntryByFqcn($payload['listeners'], 'App\\Listeners\\MultiHandlerListener');
    expect($listener)->not->toBeNull();

    $byTarget = [];
    foreach ($listener['dispatches'] as $d) {
        $byTarget[$d['target']] = $d;
    }

    expect($byTarget)->toHaveKey('App\\Jobs\\SendReceipt');
    expect($byTarget['App\\Jobs\\SendReceipt']['kind'])->toBe('job');

    expect($byTarget)->toHaveKey('App\\Events\\OrderConfirmationSent');
    expect($byTarget['App\\Events\\OrderConfirmationSent']['kind'])->toBe('event');
});

it('does not leak a closure-internal dispatch into the enclosing listener or the target event', function () {
    // SendOrderConfirmation::handle wraps an event(new OrderConfirmationSent())
    // at line 23 inside a closure. That site is tagged inClosure, so the
    // class-handler attribution (DispatchAttributionPhase / DispatchedFromPhase)
    // must skip it: it stays out of listeners[].dispatches and the target
    // event's dispatched_from.
    $payload = buildDispatchEndToEndPayload();

    $listener = dispatchEntryByFqcn($payload['listeners'], 'App\\Listeners\\SendOrderConfirmation');
    expect($listener)->not->toBeNull();
    foreach ($listener['dispatches'] as $d) {
        expect($d['line'])->not->toBe(23);
    }

    $event = dispatchEntryByFqcn($payload['events'], 'App\\Events\\OrderConfirmationSent');
    expect($event)->not->toBeNull();
    foreach ($event['dispatched_from'] as $entry) {
        $isClosureSite = $entry['file'] === 'app/Listeners/SendOrderConfirmation.php'
            && $entry['line'] === 23;
        expect($isClosureSite)->toBeFalse();
    }
});

it('matches the expected stats counts', function () {
    $payload = buildDispatchEndToEndPayload();

    expect($payload['stats']['events'])->toBe(3);
    expect($payload['stats']['listeners'])->toBe(2);
    expect($payload['stats']['observers'])->toBe(1);
    expect($payload['stats']['unresolved_dispatches'])->toBe(2);
});
