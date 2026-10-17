<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

/** @return array<string, mixed> */
function buildDispatchSitesPayload(): array
{
    $builder = new IndexBuilder;
    foreach (DefaultScanners::all() as $scanner) {
        $builder->register($scanner);
    }
    $payload = $builder->build(dirname(__DIR__).'/Fixtures/dispatch-sites-fixture-app', '12.x')->toArray();
    expect($builder->validate($payload))->toBe([]);

    return $payload;
}

it('attributes a dispatch in a helper to the handler method that reaches it, marked via', function () {
    $listener = collect(buildDispatchSitesPayload()['listeners'])->firstWhere('fqcn', 'App\\Listeners\\OnOrderPaid');

    $recompute = collect($listener['dispatches'])->where('target', 'App\\Jobs\\RecomputeTotals')->first(fn (array $d): bool => isset($d['via']));

    expect($recompute)->not->toBeNull()
        ->and($recompute['via'])->toBe(['refresh', 'recompute'])
        ->and($recompute['confidence'])->toBe('medium');

    $direct = collect($listener['dispatches'])->firstWhere('target', 'App\\Jobs\\StartJob');
    expect($direct)->not->toHaveKey('via')
        ->and($direct['confidence'])->toBe('high');
});

it('lists notification and nested batch jobs in the handler dispatches', function () {
    $payload = buildDispatchSitesPayload();
    $listener = collect($payload['listeners'])->firstWhere('fqcn', 'App\\Listeners\\OnOrderPaid');

    expect(collect($listener['dispatches'])->firstWhere('target', 'App\\Notifications\\ShippedNotice')['kind'])->toBe('notification')
        ->and(collect($listener['dispatches'])->pluck('target'))->toContain('App\\Jobs\\FinishJob');

    $notice = collect($payload['notifications'])->firstWhere('fqcn', 'App\\Notifications\\ShippedNotice');
    expect($notice['notified_from'])->toHaveCount(1);

    $finish = collect($payload['jobs'])->firstWhere('fqcn', 'App\\Jobs\\FinishJob');
    expect($finish['dispatched_from'])->toHaveCount(1);
});

it('resolves self::dispatch inside a job to the job', function () {
    $job = collect(buildDispatchSitesPayload()['jobs'])->firstWhere('fqcn', 'App\\Jobs\\RecomputeTotals');

    expect(collect($job['dispatches'])->pluck('target'))->toContain('App\\Jobs\\RecomputeTotals');
});

it('reports a dispatch to a class with no section row as target_not_indexed', function () {
    $payload = buildDispatchSitesPayload();

    $unresolved = collect($payload['unresolved_dispatches'])->firstWhere('reason', 'target_not_indexed');
    expect($unresolved['expression'])->toContain('StartLogDrain::dispatch');

    $listener = collect($payload['listeners'])->firstWhere('fqcn', 'App\\Listeners\\OnOrderPaid');
    expect(collect($listener['dispatches'])->pluck('target'))->not->toContain('App\\Actions\\StartLogDrain');
});

it('never lists a dispatches[] target without a section row', function () {
    $payload = buildDispatchSitesPayload();
    $rows = collect(['events', 'jobs', 'mailables', 'notifications'])
        ->flatMap(fn (string $s) => collect($payload[$s])->pluck('fqcn'))->all();

    foreach (['listeners', 'observers', 'jobs', 'routes', 'closure_listeners'] as $section) {
        foreach ($payload[$section] as $entry) {
            foreach ($entry['dispatches'] as $dispatch) {
                expect($rows)->toContain($dispatch['target']);
            }
        }
    }
});
