<?php

declare(strict_types=1);

use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;

/**
 * Modes recorded per `ModeDispatcher` method, in source order, keyed by the
 * short method name. Plain sites show as null.
 *
 * @return array<string, list<string|null>>
 */
function dispatchModesByMethod(string $section, string $from): array
{
    static $payload = null;
    if ($payload === null) {
        $builder = new IndexBuilder;
        DefaultScanners::registerOn($builder);
        $payload = $builder->build(dirname(__DIR__).'/Fixtures/dispatch-mode-fixture-app', '12.x')->toArray();
        expect($builder->validate($payload))->toBe([]);
    }

    $sites = [];
    foreach ($payload[$section] as $entry) {
        foreach ($entry[$from] as $site) {
            $sites[] = $site;
        }
    }
    usort($sites, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

    $out = [];
    foreach ($sites as $site) {
        $method = substr((string) strrchr($site['method'], ':'), 1);
        $out[$method][] = $site['mode'] ?? null;
    }

    return $out;
}

it('omits mode on plain job dispatches', function () {
    expect(dispatchModesByMethod('jobs', 'dispatched_from')['plain'])->toBe([null, null, null]);
});

it('records sync for dispatchSync, dispatch_sync and Bus::dispatchSync/dispatchNow', function () {
    expect(dispatchModesByMethod('jobs', 'dispatched_from')['syncForms'])
        ->toBe(array_fill(0, 4, DispatchMode::SYNC->value));
});

it('records after_response for static, facade and fluent forms', function () {
    expect(dispatchModesByMethod('jobs', 'dispatched_from')['afterResponseForms'])
        ->toBe(array_fill(0, 5, DispatchMode::AFTER_RESPONSE->value));
});

it('does not record after_response for afterResponse(false)', function () {
    expect(dispatchModesByMethod('jobs', 'dispatched_from')['afterResponseDisabled'])->toBe([null]);
});

it('records push for Queue facade pushes and skips pushRaw', function () {
    expect(dispatchModesByMethod('jobs', 'dispatched_from')['queueFacade'])
        ->toBe(array_fill(0, 5 + 1, DispatchMode::PUSH->value));
});

it('sends dynamic sync and Queue targets to unresolved_dispatches', function () {
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder);
    $payload = $builder->build(dirname(__DIR__).'/Fixtures/dispatch-mode-fixture-app', '12.x')->toArray();

    $expressions = array_column($payload['unresolved_dispatches'], 'expression');
    expect($expressions)->toContain('dispatch_sync($job)')
        ->and(array_filter($expressions, fn (string $e): bool => str_ends_with($e, 'Queue::push($job)')))->toHaveCount(1);
});

it('maps mailer forms to modes', function () {
    expect(dispatchModesByMethod('mailables', 'sent_from')['mail'])->toBe([
        null,
        'sync',
        'push',
        'push',
        null,
        'sync',
        'push',
        'push',
        'push',
        'push',
    ]);
});

it('maps notification forms to modes', function () {
    expect(dispatchModesByMethod('notifications', 'notified_from')['notifications'])
        ->toBe([null, 'sync', null, 'sync']);
});
