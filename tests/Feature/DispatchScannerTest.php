<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\DispatchSiteRecord;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Index\DispatchForm;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Scanners\DispatchScanner;

function dispatchFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/dispatch-fixture-app';
}

/**
 * @param  list<DispatchSiteRecord>  $sites
 */
function findSite(array $sites, string $file, int $line): ?DispatchSiteRecord
{
    foreach ($sites as $site) {
        if ($site->file === $file && $site->line === $line) {
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

    $reasons = array_map(fn (UnresolvedDispatchEntry $e): string => $e->reason, $result['unresolved_dispatches']);
    sort($reasons);
    expect($reasons)->toBe(['dynamic_class_name', 'string_concatenation']);
});

it('records each unresolved entry as an UnresolvedDispatchEntry DTO with file=app/...', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['unresolved_dispatches'] as $entry) {
        expect($entry)->toBeInstanceOf(UnresolvedDispatchEntry::class);
        expect($entry->file)->toBe('app/Services/Checkout.php');
        expect($entry->line)->toBeInt();
        expect($entry->expression)->toBeString();
    }
});

it('emits nine recognised dispatch sites in the fixture', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    expect($result['_dispatch_sites'])->toHaveCount(9);
});

it('records each site as a DispatchSiteRecord DTO with file=app/...', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        expect($site)->toBeInstanceOf(DispatchSiteRecord::class);
        expect($site->file)->toBeString();
        expect($site->file)->not->toContain('\\');
        expect($site->file)->toStartWith('app/');
        expect($site->line)->toBeInt();
        expect($site->classFqcn)->toBeString();
        expect($site->method)->toBeString();
        expect($site->confidence)->toBe('high');
    }
});

it('classifies the helper, facade, job_helper, and dispatchable forms correctly', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());
    $sites = $result['_dispatch_sites'];

    $helper = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 18);
    expect($helper)->not->toBeNull();
    expect($helper->form)->toBe(DispatchForm::HELPER);
    expect($helper->provisionalKind)->toBe(DispatchKinds::EVENT);
    expect($helper->target)->toBe('App\\Events\\OrderConfirmationSent');
    expect($helper->classFqcn)->toBe('App\\Listeners\\SendOrderConfirmation');
    expect($helper->method)->toBe('handle');

    $dispatchable = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 19);
    expect($dispatchable)->not->toBeNull();
    expect($dispatchable->form)->toBe(DispatchForm::DISPATCHABLE);
    expect($dispatchable->provisionalKind)->toBe(DispatchKinds::AMBIGUOUS);
    expect($dispatchable->target)->toBe('App\\Jobs\\SendReceipt');

    $facade = findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 20);
    expect($facade)->not->toBeNull();
    expect($facade->form)->toBe(DispatchForm::FACADE);
    expect($facade->provisionalKind)->toBe(DispatchKinds::EVENT);
    expect($facade->target)->toBe('App\\Events\\InventoryAdjusted');

    $busFacade = findSite($sites, 'app/Observers/UserObserver.php', 15);
    expect($busFacade)->not->toBeNull();
    expect($busFacade->form)->toBe(DispatchForm::JOB_HELPER);
    expect($busFacade->provisionalKind)->toBe(DispatchKinds::JOB);
    expect($busFacade->target)->toBe('App\\Jobs\\SendReceipt');
    expect($busFacade->method)->toBe('created');
});

it('does not record sites inside closures', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());
    $sites = $result['_dispatch_sites'];

    expect(findSite($sites, 'app/Listeners/SendOrderConfirmation.php', 23))->toBeNull();
});

it('does not record dispatch_sync as a site or as unresolved', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        if ($site->file === 'app/Services/Checkout.php') {
            expect($site->line)->not->toBe(17);
        }
    }
    foreach ($result['unresolved_dispatches'] as $entry) {
        if ($entry->file === 'app/Services/Checkout.php') {
            expect($entry->line)->not->toBe(17);
        }
    }
});

it('sorts unresolved_dispatches by file then line', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    $keys = array_map(
        fn (UnresolvedDispatchEntry $e): array => [$e->file, $e->line],
        $result['unresolved_dispatches']
    );
    $sorted = $keys;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($keys)->toBe($sorted);
});

it('sorts _dispatch_sites by file then line', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    $keys = array_map(
        fn (DispatchSiteRecord $s): array => [$s->file, $s->line],
        $result['_dispatch_sites']
    );
    $sorted = $keys;
    usort($sorted, fn ($a, $b) => $a <=> $b);
    expect($keys)->toBe($sorted);
});

it('emits forward-slash relative paths', function () {
    $result = (new DispatchScanner)->scan(dispatchFixturePath());

    foreach ($result['_dispatch_sites'] as $site) {
        expect($site->file)->not->toContain('\\');
    }
    foreach ($result['unresolved_dispatches'] as $entry) {
        expect($entry->file)->not->toContain('\\');
    }
});
