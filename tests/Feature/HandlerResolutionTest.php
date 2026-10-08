<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\ObserverScanner;

function handlerFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/handler-resolution-fixture-app';
}

/**
 * @return array<string, list<string>> listener FQCN => sorted "Event::method" pairs
 */
function discoveredListeners(): array
{
    $out = [];
    foreach ((new ListenerScanner)->scan(handlerFixturePath())['listeners'] as $entry) {
        $pairs = array_map(fn ($h): string => class_basename($h->event).'::'.$h->method, $entry->handles);
        sort($pairs);
        $out[class_basename($entry->fqcn)] = $pairs;
    }

    return $out;
}

it('resolves handle in a parent, a trait and __invoke', function (string $listener, array $handles) {
    expect(discoveredListeners()[$listener])->toBe($handles);
})->with([
    'parent handle' => ['ChildListener', ['OrderPlaced::handle']],
    'trait handle' => ['TraitListener', ['OrderShipped::handle']],
    '__invoke' => ['InvokableListener', ['OrderPlaced::__invoke']],
    'handleFoo and handle, hidden skipped' => ['MultiMethodListener', ['OrderPlaced::handle', 'OrderRefunded::handleRefund']],
    'trait alias keeps both names' => ['AliasListener', ['OrderShipped::handle', 'OrderShipped::handleShipped']],
    'insteadof plus alias' => ['InsteadofListener', ['OrderRefunded::handleRefunded', 'OrderShipped::handle']],
    'union type, builtin member dropped' => ['UnionListener', ['OrderPlaced::handle', 'OrderShipped::handle']],
    'nullable type' => ['NullableListener', ['OrderPlaced::handle']],
    'untyped parameter has no event' => ['UntypedListener', []],
    'vendor parent stays opaque' => ['VendorParentListener', ['OrderPlaced::handle']],
]);

it('does not emit abstract classes, interfaces, traits, protected aliases or parameterless handlers', function () {
    $listeners = discoveredListeners();

    foreach (['BaseListener', 'Contract', 'HandlesShipped', 'HandlesRefunded', 'HiddenListener', 'NoParameterListener'] as $name) {
        expect($listeners)->not->toHaveKey($name);
    }
});

it('marks a child of a parent that owns handle as queued when it implements ShouldQueue', function () {
    $entries = [];
    foreach ((new ListenerScanner)->scan(handlerFixturePath())['listeners'] as $entry) {
        $entries[class_basename($entry->fqcn)] = $entry;
    }

    expect($entries['QueuedChildListener']->queued)->toBeTrue();
    expect($entries['QueuedChildListener']->handles[0]->method)->toBe('handle');
    expect($entries['ChildListener']->queued)->toBeFalse();
});

it('resolves observer hooks from parents and traits and ignores booting and booted', function () {
    $hooks = [];
    foreach ((new ObserverScanner)->scan(handlerFixturePath())['observers'] as $entry) {
        $hooks[class_basename($entry->fqcn)] = $entry->hooks;
    }

    expect($hooks['ChildObserver'])->toBe(['created', 'deleted', 'updated']);
    expect($hooks['TraitObserver'])->toBe(['created', 'saving']);
    expect($hooks['BootObserver'])->toBe(['created']);
});

it('emits model_events rows for inherited hooks only', function () {
    $ids = array_map(fn ($e): string => $e->id, (new ObserverScanner)->scan(handlerFixturePath())['model_events']);

    expect($ids)->toContain('eloquent.updated: App\\Models\\Post');
    expect($ids)->toContain('eloquent.saving: App\\Models\\Comment');
    expect($ids)->not->toContain('eloquent.booting: App\\Models\\Tag');
    expect($ids)->not->toContain('eloquent.booted: App\\Models\\Tag');
});
