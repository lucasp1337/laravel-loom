<?php

declare(strict_types=1);

use Lucasp\Atlas\Index\IndexBuilder;
use Lucasp\Atlas\Scanners\EventScanner;

function eventEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/event-fixture-app';
}

it('produces a schema-valid index when only EventScanner is registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);

    $index = $builder->build(eventEndToEndFixturePath(), '12.x');
    $payload = $index->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('counts discovered events in stats.events', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);

    $payload = $builder->build(eventEndToEndFixturePath(), '12.x')->toArray();

    // Three classes under app/Events/ (OrderPlaced, Nested\InventoryAdjusted,
    // AbstractDomainEvent) plus App\Outside\CustomEvent, which is discovered
    // via the helper-form event(new CustomEvent(...)) in Checkout.php and
    // bypasses the app/Events/ trim. SendReceipt is trimmed because every
    // hit is the Dispatchable static-call form.
    expect($payload['stats']['events'])->toBe(4);
});

it('includes the known event FQCNs', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);

    $payload = $builder->build(eventEndToEndFixturePath(), '12.x')->toArray();

    $fqcns = array_column($payload['events'], 'fqcn');

    expect($fqcns)->toContain('App\\Events\\OrderPlaced');
    expect($fqcns)->toContain('App\\Events\\Nested\\InventoryAdjusted');
    expect($fqcns)->toContain('App\\Events\\AbstractDomainEvent');
    expect($fqcns)->toContain('App\\Outside\\CustomEvent');
});

it('leaves non-event sections as empty arrays, not null', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);

    $payload = $builder->build(eventEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['listeners'])->toBe([]);
    expect($payload['observers'])->toBe([]);
    expect($payload['model_events'])->toBe([]);
    expect($payload['unresolved_dispatches'])->toBe([]);

    expect($payload['stats']['listeners'])->toBe(0);
    expect($payload['stats']['observers'])->toBe(0);
    expect($payload['stats']['unresolved_dispatches'])->toBe(0);
});
