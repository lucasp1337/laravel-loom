<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;

function jobsEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/jobs-fixture-app';
}

function buildJobsEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);

    return $builder->build(jobsEndToEndFixturePath(), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function jobEntryByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('produces a schema-valid index with EventScanner, JobsScanner, and DispatchScanner registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(jobsEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('counts discovered jobs in stats.jobs', function () {
    $payload = buildJobsEndToEndPayload();

    expect($payload['stats']['jobs'])->toBe(5);
    expect($payload['jobs'])->toHaveCount(5);
});

it('includes the known job FQCNs', function () {
    $payload = buildJobsEndToEndPayload();

    $fqcns = array_column($payload['jobs'], 'fqcn');

    expect($fqcns)->toContain('App\\Jobs\\ProcessOrder');
    expect($fqcns)->toContain('App\\Jobs\\SendInvoice');
    expect($fqcns)->toContain('App\\Jobs\\RunReport');
    expect($fqcns)->toContain('App\\Domain\\Billing\\Jobs\\ChargeCustomer');
});

it('populates jobs[ProcessOrder].dispatched_from via the Phase 5 job branch', function () {
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();

    $methods = array_column($entry['dispatched_from'], 'method');
    expect($methods)->toContain('App\\Services\\Checkout::finalize');
});

it('populates jobs[ChargeCustomer].dispatched_from for jobs discovered via PSR-4 seeding', function () {
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Domain\\Billing\\Jobs\\ChargeCustomer');
    expect($entry)->not->toBeNull();
    expect($entry['dispatched_from'])->not->toBe([]);

    $methods = array_column($entry['dispatched_from'], 'method');
    expect($methods)->toContain('App\\Services\\Billing::charge');
});

it('populates jobs[ProcessOrder].dispatches from the job handle() body (Phase 4 branch)', function () {
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();
    expect($entry['dispatches'])->not->toBe([]);

    $byTarget = [];
    foreach ($entry['dispatches'] as $d) {
        $byTarget[$d['target']] = $d;
    }

    expect($byTarget)->toHaveKey('App\\Events\\OrderProcessed');
    expect($byTarget['App\\Events\\OrderProcessed']['kind'])->toBe('event');
});

it('leaves non-job sections sensibly populated and non-null', function () {
    $payload = buildJobsEndToEndPayload();

    expect($payload['observers'])->toBe([]);
    expect($payload['model_events'])->toBe([]);
    expect($payload['stats']['observers'])->toBe(0);
});

it('resolves chain-wrapped job dispatches into dispatched_from (issue #31)', function () {
    // Regression for issue #31: dispatch((new ProcessOrder())->delay(60)) wraps
    // the job in a leading fluent MethodCall chain. The chain must be unwrapped
    // so the FQCN resolves and the site lands in jobs[ProcessOrder].dispatched_from[],
    // NOT unresolved_dispatches[].
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();

    $chained = array_values(array_filter(
        $entry['dispatched_from'],
        static fn (array $site): bool => ($site['method'] ?? null) === 'App\\Services\\Billing::chargeWithDelay',
    ));

    expect($chained)->toHaveCount(1);
    expect($chained[0]['line'])->toBe(28);

    // The chained site must NOT have leaked into unresolved_dispatches.
    $unresolvedLines = array_column($payload['unresolved_dispatches'], 'line');
    expect($unresolvedLines)->not->toContain(28);
});

it('captures outer PendingDispatch chain overrides in dispatched_from (issue #32)', function () {
    // ProcessOrder::dispatch($o)->onQueue('high')->onConnection('redis')->delay(60)
    // is the (c) outer PendingDispatch form. The modifiers sit on the chain
    // returned by ::dispatch(), not on the argument; the resulting site must
    // carry an overrides object on its dispatched_from entry.
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();

    $site = array_values(array_filter(
        $entry['dispatched_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\Billing::chargeWithOuterChain',
    ));

    expect($site)->toHaveCount(1);
    expect($site[0]['line'])->toBe(36);
    expect($site[0])->toHaveKey('overrides');
    expect($site[0]['overrides'])->toBe([
        'connection' => 'redis',
        'queue' => 'high',
        'delay' => 60,
    ]);
});

it('captures outer ->afterCommit() override in dispatched_from (issue #32)', function () {
    // dispatch(new ProcessOrder())->afterCommit() — outer chain, single boolean
    // modifier. afterCommit is emitted only as true.
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();

    $site = array_values(array_filter(
        $entry['dispatched_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\Billing::chargeAfterCommit',
    ));

    expect($site)->toHaveCount(1);
    expect($site[0]['line'])->toBe(46);
    expect($site[0]['overrides'])->toBe(['after_commit' => true]);
});

it('captures inner-chain delay override and omits overrides on plain sites (issue #32)', function () {
    // The #31 chain-wrapped dispatch at Billing::chargeWithDelay (line 28) is
    // dispatch((new ProcessOrder())->delay(60)) — now positive coverage for the
    // inner (a) chain overrides. A plain dispatch site (no modifiers) must have
    // NO overrides key, keeping its JSON byte-identical to pre-#32 output.
    $payload = buildJobsEndToEndPayload();

    $entry = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($entry)->not->toBeNull();

    $delaySite = array_values(array_filter(
        $entry['dispatched_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\Billing::chargeWithDelay',
    ));
    expect($delaySite)->toHaveCount(1);
    expect($delaySite[0]['overrides'])->toBe(['delay' => 60]);

    // Checkout::finalize dispatches ProcessOrder with no modifiers.
    $plainSites = array_values(array_filter(
        $entry['dispatched_from'],
        static fn (array $s): bool => ($s['method'] ?? null) === 'App\\Services\\Checkout::finalize',
    ));
    expect($plainSites)->not->toBe([]);
    foreach ($plainSites as $s) {
        expect($s)->not->toHaveKey('overrides');
    }
});

// gap: Bus::chain([new ProcessOrder, new SendInvoice]) does not currently
// surface either job in dispatched_from. The DispatchSiteVisitor only
// recognises direct dispatch forms (helper, facade, Dispatchable static).
// Asserting the no-op behaviour so a future fix is detectable.
it('does NOT surface Bus::chain([...]) targets in dispatched_from (documented silent gap)', function () {
    $payload = buildJobsEndToEndPayload();

    // ProcessOrder IS dispatched from Checkout::finalize, but NOT from
    // Billing::charge via the Bus::chain expression — guard only on Billing.
    $processOrder = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\ProcessOrder');
    expect($processOrder)->not->toBeNull();
    $methods = array_column($processOrder['dispatched_from'], 'method');
    expect($methods)->not->toContain('App\\Services\\Billing::charge');

    $sendInvoice = jobEntryByFqcn($payload['jobs'], 'App\\Jobs\\SendInvoice');
    expect($sendInvoice)->not->toBeNull();
    expect($sendInvoice['dispatched_from'])->toBe([]);
});
