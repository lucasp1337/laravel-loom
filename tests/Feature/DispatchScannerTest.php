<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\DispatchScanner;

function dispatchFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/dispatch-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $sites
 */
function findSite(array $sites, string $file, int $line): ?array
{
    foreach ($sites as $site) {
        if (($site['file'] ?? null) === $file && ($site['line'] ?? null) === $line) {
            return $site;
        }
    }

    return null;
}

it('returns both unresolved_dispatches and _dispatch_sites keys', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    expect(array_keys($result))->toBe(['unresolved_dispatches', '_dispatch_sites']);
});

it('returns empty arrays when app/ does not exist', function () {
    $result = (new DispatchScanner)->scan(sys_get_temp_dir().'/loom-no-such-dir');

    expect($result['unresolved_dispatches'])->toBe([]);
    expect($result['_dispatch_sites'])->toBe([]);
});

it('emits exactly two unresolved entries for the fixture', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    expect($result['unresolved_dispatches'])->toHaveCount(2);

    $reasons = array_column($result['unresolved_dispatches'], 'reason');
    sort($reasons);
    expect($reasons)->toBe(['dynamic_class_name', 'string_concatenation']);
});

it('records each unresolved entry with file, line, expression, reason', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['unresolved_dispatches'] as $entry) {
        expect($entry)->toHaveKeys(['file', 'line', 'expression', 'reason']);
        expect($entry['file'])->toBe('app/Services/Checkout.php');
        expect($entry['line'])->toBeInt();
        expect($entry['expression'])->toBeString();
    }
});

it('emits nine recognised dispatch sites in the fixture', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    // SendOrderConfirmation::handle (3) + SendOrderConfirmation::otherMethod (1)
    // + UserObserver::created (1) + UserObserver::utility (1) + Checkout::finalize (1)
    // + MultiHandlerListener::handlePlaced (1) + MultiHandlerListener::handleAdjusted (1).
    expect($result['_dispatch_sites'])->toHaveCount(9);
});

it('records each site with target, kind, form, file, line, class and method', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        expect($site)->toHaveKeys([
            'classFqcn', 'method', 'target', 'form', 'provisionalKind',
            'file', 'line', 'confidence',
        ]);
        expect($site['file'])->toBeString();
        expect($site['file'])->not->toContain('\\');
        expect($site['file'])->toStartWith('app/');
        expect($site['line'])->toBeInt();
        expect($site['classFqcn'])->toBeString();
        expect($site['method'])->toBeString();
        expect($site['confidence'])->toBe('high');
    }
});

it('classifies the helper, facade, job_helper, and dispatchable forms correctly', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());
    $sites = $result['_dispatch_sites'];

    $helper = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 18);
    expect($helper)->not->toBeNull();
    expect($helper['form'])->toBe('helper');
    expect($helper['provisionalKind'])->toBe('event');
    expect($helper['target'])->toBe('App\\Events\\OrderConfirmationSent');
    expect($helper['classFqcn'])->toBe('App\\Listeners\\SendOrderConfirmation');
    expect($helper['method'])->toBe('handle');

    $dispatchable = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 19);
    expect($dispatchable)->not->toBeNull();
    expect($dispatchable['form'])->toBe('dispatchable');
    expect($dispatchable['provisionalKind'])->toBe('ambiguous');
    expect($dispatchable['target'])->toBe('App\\Jobs\\SendReceipt');

    $facade = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 20);
    expect($facade)->not->toBeNull();
    expect($facade['form'])->toBe('facade');
    expect($facade['provisionalKind'])->toBe('event');
    expect($facade['target'])->toBe('App\\Events\\InventoryAdjusted');

    $busFacade = findSite($sites, 'app/Observers/UserObserver.php', 15);
    expect($busFacade)->not->toBeNull();
    expect($busFacade['form'])->toBe('job_helper');
    expect($busFacade['provisionalKind'])->toBe('job');
    expect($busFacade['target'])->toBe('App\\Jobs\\SendReceipt');
    expect($busFacade['method'])->toBe('created');
});

it('does not record sites inside closures', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());
    $sites = $result['_dispatch_sites'];

    // The closure-internal dispatch is on line 23 of SendOrderConfirmation.php.
    expect(findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 23))->toBeNull();
});

it('does not record dispatch_sync as a site or as unresolved', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        if ($site['file'] === 'app/Services/Checkout.php') {
            expect($site['line'])->not->toBe(17);
        }
    }
    foreach ($result['unresolved_dispatches'] as $entry) {
        if ($entry['file'] === 'app/Services/Checkout.php') {
            expect($entry['line'])->not->toBe(17);
        }
    }
});

it('sorts unresolved_dispatches by file then line', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    $keys = array_map(
        fn (array $e) => [$e['file'], $e['line']],
        $result['unresolved_dispatches']
    );
    $sorted = $keys;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($keys)->toBe($sorted);
});

it('sorts _dispatch_sites by file then line', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    $keys = array_map(
        fn (array $s) => [$s['file'], $s['line']],
        $result['_dispatch_sites']
    );
    $sorted = $keys;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($keys)->toBe($sorted);
});

it('emits forward-slash relative paths', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        expect($site['file'])->not->toContain('\\');
    }
    foreach ($result['unresolved_dispatches'] as $entry) {
        expect($entry['file'])->not->toContain('\\');
    }
});
