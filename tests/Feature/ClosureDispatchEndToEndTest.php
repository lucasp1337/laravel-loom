<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;

function closureDispatchFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/closure-dispatch-fixture-app';
}

function buildClosureDispatchPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);

    return $builder->build(closureDispatchFixturePath(), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $closures
 */
function closureAt(array $closures, string $event, int $line): ?array
{
    foreach ($closures as $closure) {
        if (($closure['event'] ?? null) === $event && ($closure['line'] ?? null) === $line) {
            return $closure;
        }
    }

    return null;
}

it('produces a schema-valid index with closure listeners and a DispatchScanner', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(closureDispatchFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('discovers all four closure listeners from the provider', function () {
    $payload = buildClosureDispatchPayload();

    expect($payload['stats']['closure_listeners'])->toBe(4);
    expect($payload['closure_listeners'])->toHaveCount(4);
});

it('emits end_line >= line for every closure listener', function () {
    $payload = buildClosureDispatchPayload();

    foreach ($payload['closure_listeners'] as $closure) {
        expect($closure)->toHaveKey('end_line');
        expect($closure['end_line'])->toBeGreaterThanOrEqual($closure['line']);
    }
});

it('records the multi-line closure spans from the fixture', function () {
    $payload = buildClosureDispatchPayload();

    // The dispatching closures are multi-line; assert their captured spans so
    // the line-span attribution has the right bounds to match against.
    expect(closureAt($payload['closure_listeners'], 'App\\Events\\StockLow', 19))
        ->not->toBeNull()
        ->and(closureAt($payload['closure_listeners'], 'App\\Events\\StockLow', 19)['end_line'])->toBe(21);
    expect(closureAt($payload['closure_listeners'], 'App\\Events\\OrderPlaced', 24)['end_line'])->toBe(26);
    expect(closureAt($payload['closure_listeners'], 'App\\Events\\OrderPlaced', 29)['end_line'])->toBe(31);
    expect(closureAt($payload['closure_listeners'], 'App\\Events\\InventoryAdjusted', 36)['end_line'])->toBe(40);
});

it('leaves the empty-bodied closure listener with dispatches: []', function () {
    $payload = buildClosureDispatchPayload();

    // Regression guard for the byte-identical empty case: a closure whose body
    // dispatches nothing must stay [], whether or not the attribution phase
    // populates dispatching closures.
    $empty = closureAt($payload['closure_listeners'], 'App\\Events\\StockLow', 19);
    expect($empty)->not->toBeNull();
    expect($empty['dispatches'])->toBe([]);
});

it('attributes the EVENT dispatched inside a closure listener body', function () {
    $payload = buildClosureDispatchPayload();

    // OrderPlaced closure at line 24-26 contains `event(new OrderConfirmationSent(...))`
    // at line 25.
    $closure = closureAt($payload['closure_listeners'], 'App\\Events\\OrderPlaced', 24);
    expect($closure)->not->toBeNull();

    expect($closure['dispatches'])->toBe([
        [
            'target' => 'App\\Events\\OrderConfirmationSent',
            'kind' => 'event',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 25,
        ],
    ]);
});

it('attributes the JOB dispatched inside a closure listener body', function () {
    $payload = buildClosureDispatchPayload();

    // OrderPlaced closure at line 29-31 contains `dispatch(new SendReceipt)` at line 30.
    $closure = closureAt($payload['closure_listeners'], 'App\\Events\\OrderPlaced', 29);
    expect($closure)->not->toBeNull();

    expect($closure['dispatches'])->toBe([
        [
            'target' => 'App\\Jobs\\SendReceipt',
            'kind' => 'job',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 30,
        ],
    ]);
});

it('attributes multiple dispatches in one closure body sorted by (file, line, target)', function () {
    $payload = buildClosureDispatchPayload();

    // InventoryAdjusted closure at line 36-40:
    //   line 37  event(new OrderConfirmationSent(...))  -> event
    //   line 38  dispatch(new SendReceipt)              -> job
    //   line 39  OrderPlaced::dispatch(...)             -> event (disambiguated)
    // Sorted by (file, line, target).
    $closure = closureAt($payload['closure_listeners'], 'App\\Events\\InventoryAdjusted', 36);
    expect($closure)->not->toBeNull();

    expect($closure['dispatches'])->toBe([
        [
            'target' => 'App\\Events\\OrderConfirmationSent',
            'kind' => 'event',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 37,
        ],
        [
            'target' => 'App\\Jobs\\SendReceipt',
            'kind' => 'job',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 38,
        ],
        [
            'target' => 'App\\Events\\OrderPlaced',
            'kind' => 'event',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 39,
        ],
    ]);
});
