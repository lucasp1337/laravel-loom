<?php

declare(strict_types=1);

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\CheckRunner;
use Lucasp\Loom\Check\Result\RuleReport;
use Lucasp\Loom\Check\RuleKey;

/**
 * A context that trips both OrphanListenersRule (a handle-less listener) and
 * OrphanEventsRule (an event with no producer and no consumer), so several
 * rules report at once.
 */
function runnerFailingContext(): CheckContext
{
    return new CheckContext(
        [
            'events' => [
                ['fqcn' => 'App\\Events\\Lonely', 'handled_by' => [], 'dispatched_from' => []],
            ],
            'listeners' => [
                ['fqcn' => 'App\\Listeners\\Idle', 'handles' => []],
            ],
            'unresolved_dispatches' => [],
            'jobs' => [],
        ],
        null,
        false,
    );
}

it('runs every registered rule in registry order', function () {
    $result = (new CheckRunner)->run(runnerFailingContext());

    $keys = array_map(static fn (RuleReport $r): RuleKey => $r->key, $result->reports);

    expect($keys)->toBe([
        RuleKey::SCHEMA,
        RuleKey::ORPHAN_LISTENERS,
        RuleKey::ORPHAN_EVENTS,
        RuleKey::UNRESOLVED_DISPATCHES,
        RuleKey::CYCLIC_DISPATCH,
    ]);
});

it('marks a skipped rule as skipped with no violations', function () {
    $result = (new CheckRunner)->run(runnerFailingContext(), [RuleKey::ORPHAN_EVENTS->value]);

    $report = collect($result->reports)->firstWhere('key', RuleKey::ORPHAN_EVENTS);

    expect($report)->toBeInstanceOf(RuleReport::class);
    expect($report->skipped)->toBeTrue();
    expect($report->violations)->toBe([]);
    expect($report->passed())->toBeTrue();
});

it('does not count violations from a skipped rule', function () {
    $withOrphanEvents = (new CheckRunner)->run(runnerFailingContext());
    $skippingOrphanEvents = (new CheckRunner)->run(runnerFailingContext(), [RuleKey::ORPHAN_EVENTS->value]);

    // Skipping the orphan-events rule removes exactly its one violation.
    expect($skippingOrphanEvents->violationCount())
        ->toBe($withOrphanEvents->violationCount() - 1);
});

it('fails the result when any rule has violations', function () {
    $result = (new CheckRunner)->run(runnerFailingContext());

    expect($result->passed())->toBeFalse();
});

it('passes the result when every failing rule is skipped', function () {
    // OrphanListeners and OrphanEvents are the only rules with violations here;
    // skip both and the run should pass. (Schema is skipped too since the bare
    // context is not a full schema-valid index.)
    $result = (new CheckRunner)->run(runnerFailingContext(), [
        RuleKey::SCHEMA->value,
        RuleKey::ORPHAN_LISTENERS->value,
        RuleKey::ORPHAN_EVENTS->value,
    ]);

    expect($result->passed())->toBeTrue();
});

it('sums violationCount across all reporting rules', function () {
    $result = (new CheckRunner)->run(runnerFailingContext());

    $manual = array_sum(array_map(
        static fn (RuleReport $r): int => count($r->violations),
        $result->reports,
    ));

    expect($result->violationCount())->toBe($manual);
});
