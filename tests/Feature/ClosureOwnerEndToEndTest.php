<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

function closureOwnerPayload(): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder);

    return $builder->build(dirname(__DIR__).'/Fixtures/closure-owner-fixture-app', '12.x')->toArray();
}

/**
 * @return array<string, mixed>
 */
function ownerEvent(array $payload, string $name): array
{
    foreach ($payload['events'] as $event) {
        if ($event['fqcn'] === 'App\\Events\\'.$name) {
            return $event;
        }
    }

    throw new RuntimeException("Event {$name} not found");
}

it('is schema valid', function () {
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder);
    $payload = $builder->build(dirname(__DIR__).'/Fixtures/closure-owner-fixture-app', '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('attributes each pass-through closure form once to the enclosing method', function (string $event, string $method) {
    $sites = ownerEvent(closureOwnerPayload(), $event)['dispatched_from'];

    expect($sites)->toHaveCount(1);
    expect($sites[0]['method'])->toBe('App\\Services\\OrderService::'.$method);
})->with([
    'arrow fn' => ['OrderPlaced', 'arrow'],
    'closure' => ['OrderShipped', 'closure'],
    'each' => ['ItemAdded', 'each'],
    'tap static closure' => ['Tapped', 'tapped'],
    'variable-assigned closure' => ['Notified', 'variable'],
    'afterCommit' => ['Committed', 'afterCommit'],
    'nested sync closures' => ['Nested', 'nested'],
]);

it('keeps a registration closure as the owner of its dispatches', function () {
    $payload = closureOwnerPayload();

    expect(ownerEvent($payload, 'Child')['dispatched_from'])->toBe([]);

    expect($payload['closure_listeners'])->toHaveCount(1);
    $dispatches = $payload['closure_listeners'][0]['dispatches'];
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['target'])->toBe('App\\Events\\Child');
});

it('reports an unresolved dispatch inside a closure', function () {
    $payload = closureOwnerPayload();

    expect($payload['unresolved_dispatches'])->toHaveCount(1);
    expect($payload['unresolved_dispatches'][0]['file'])->toBe('app/Services/OrderService.php');
    expect($payload['unresolved_dispatches'][0]['reason'])->toBe('dynamic_class_name');
});
