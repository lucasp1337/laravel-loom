<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

function dispatchesEventsPayload(): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder);
    $payload = $builder->build(dirname(__DIR__).'/Fixtures/dispatches-events-fixture-app', '12.x')->toArray();
    expect($builder->validate($payload))->toBe([]);

    return $payload;
}

it('records each $dispatchesEvents entry as a dispatch site of the event', function () {
    $payload = dispatchesEventsPayload();

    $byFqcn = [];
    foreach ($payload['events'] as $event) {
        $byFqcn[$event['fqcn']] = $event;
    }

    expect($byFqcn['App\\Events\\InvoiceCreated']['dispatched_from'])->toHaveCount(1);
    expect($byFqcn['App\\Events\\InvoiceCreated']['dispatched_from'][0]['method'])
        ->toBe('App\\Models\\Invoice::$dispatchesEvents[created]');
    expect($byFqcn['App\\Events\\InvoiceCreated']['dispatched_from'][0]['file'])->toBe('app/Models/Invoice.php');
    expect($byFqcn['App\\Events\\InvoiceDeleted']['dispatched_from'])->toHaveCount(1);
});

it('discovers an event class that lives outside app/Events', function () {
    $payload = dispatchesEventsPayload();

    $fqcns = array_column($payload['events'], 'fqcn');
    expect($fqcns)->toContain('App\\Domain\\InvoicePaid');
});

it('ignores entries without a class constant value', function () {
    $payload = dispatchesEventsPayload();

    expect($payload['events'])->toHaveCount(3);
    expect($payload['unresolved_dispatches'])->toBe([]);
});
