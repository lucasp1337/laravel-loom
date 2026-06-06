<?php

declare(strict_types=1);

use Lucasp\Loom\Diff\IndexDiffer;
use Lucasp\Loom\Diff\Result\ChangedEntry;
use Lucasp\Loom\Diff\Result\DiffResult;
use Lucasp\Loom\Diff\Result\FieldChange;

/**
 * A minimal but section-complete index. Only the section bodies matter to the
 * differ; metadata blocks are deliberately present so the "metadata churn is
 * ignored" tests have something to mutate.
 *
 * @return array<string,mixed>
 */
function differBaseIndex(): array
{
    return [
        'loom_version' => '1.0.0',
        'laravel_version' => '12.x',
        'scanned_at' => '2026-01-01T00:00:00+00:00',
        'stats' => ['events' => 1, 'listeners' => 1],
        'events' => [
            [
                'fqcn' => 'App\\Events\\OrderPlaced',
                'kind' => 'event',
                'file' => 'app/Events/OrderPlaced.php',
                'line' => 10,
                'handled_by' => [
                    ['listener' => 'App\\Listeners\\SendMail', 'method' => 'handle'],
                ],
                'dispatched_from' => [
                    ['file' => 'app/Services/Checkout.php', 'line' => 42, 'method' => 'dispatch', 'overrides' => []],
                ],
            ],
        ],
        'listeners' => [
            [
                'fqcn' => 'App\\Listeners\\SendMail',
                'file' => 'app/Listeners/SendMail.php',
                'line' => 8,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [
                    ['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle'],
                ],
                'dispatches' => [],
            ],
        ],
        'observers' => [],
        'model_events' => [],
        'jobs' => [],
        'unresolved_dispatches' => [],
        'closure_listeners' => [],
        'scheduled' => [],
        'mailables' => [],
        'notifications' => [],
    ];
}

function differ(): IndexDiffer
{
    return new IndexDiffer;
}

/**
 * Assert two DiffResults are structurally identical (same section keys, same
 * added/removed/changed buckets, same order). Used for determinism checks.
 */
function expectSameDiff(DiffResult $a, DiffResult $b): void
{
    expect(array_keys($a->sections()))->toBe(array_keys($b->sections()));

    foreach ($a->sections() as $section => $diffA) {
        $diffB = $b->sections()[$section];
        expect($diffA->added)->toEqual($diffB->added);
        expect($diffA->removed)->toEqual($diffB->removed);
        expect(array_map(static fn (ChangedEntry $c): string => $c->identity, $diffA->changed))
            ->toBe(array_map(static fn (ChangedEntry $c): string => $c->identity, $diffB->changed));
        expect($diffA->changed)->toEqual($diffB->changed);
    }
}

// -----------------------------------------------------------------------------
// Identity / no-op
// -----------------------------------------------------------------------------

it('reports no changes for identical inputs', function () {
    $index = differBaseIndex();

    $result = differ()->diff($index, $index);

    expect($result->hasChanges())->toBeFalse();
    foreach ($result->sections() as $diff) {
        expect($diff->isEmpty())->toBeTrue();
    }
});

it('does not report a matched pair with zero field changes and zero sublist deltas as changed', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    // Reorder the handled_by members and dispatched_from members; identity and
    // membership are unchanged, so nothing should surface.
    $new['events'][0]['handled_by'][] = $new['events'][0]['handled_by'][0];
    array_shift($new['events'][0]['handled_by']);

    $result = differ()->diff($old, $new);

    expect($result->sections()['events']->changed)->toBe([]);
    expect($result->hasChanges())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Order-independence / determinism (headline invariant)
// -----------------------------------------------------------------------------

it('is order-independent: shuffling entry order yields an identical diff', function () {
    $old = differBaseIndex();
    $old['events'][] = [
        'fqcn' => 'App\\Events\\OrderShipped',
        'kind' => 'event',
        'file' => 'app/Events/OrderShipped.php',
        'line' => 5,
        'handled_by' => [],
        'dispatched_from' => [],
    ];
    $old['events'][] = [
        'fqcn' => 'App\\Events\\OrderCancelled',
        'kind' => 'event',
        'file' => 'app/Events/OrderCancelled.php',
        'line' => 9,
        'handled_by' => [],
        'dispatched_from' => [],
    ];

    $new = $old;
    // Remove OrderPlaced, add a fresh event, mutate OrderShipped's line.
    $new['events'] = array_values(array_filter(
        $new['events'],
        static fn (array $e): bool => $e['fqcn'] !== 'App\\Events\\OrderPlaced',
    ));
    foreach ($new['events'] as &$e) {
        if ($e['fqcn'] === 'App\\Events\\OrderShipped') {
            $e['line'] = 6;
        }
    }
    unset($e);
    $new['events'][] = [
        'fqcn' => 'App\\Events\\OrderRefunded',
        'kind' => 'event',
        'file' => 'app/Events/OrderRefunded.php',
        'line' => 3,
        'handled_by' => [],
        'dispatched_from' => [],
    ];

    $canonical = differ()->diff($old, $new);

    // Now shuffle both sides several ways and assert the result never moves.
    $shuffles = [
        static fn (array $list): array => array_reverse($list),
        static function (array $list): array {
            usort($list, static fn (array $a, array $b): int => $b['line'] <=> $a['line']);

            return $list;
        },
        static function (array $list): array {
            // Rotate by one.
            if ($list === []) {
                return $list;
            }
            $first = array_shift($list);
            $list[] = $first;

            return $list;
        },
    ];

    foreach ($shuffles as $shuffleOld) {
        foreach ($shuffles as $shuffleNew) {
            $o = $old;
            $n = $new;
            $o['events'] = $shuffleOld($o['events']);
            $n['events'] = $shuffleNew($n['events']);

            expectSameDiff(differ()->diff($o, $n), $canonical);
        }
    }
});

it('is order-independent for sublist members', function () {
    $old = differBaseIndex();
    $old['events'][0]['handled_by'] = [
        ['listener' => 'App\\Listeners\\A', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\B', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\C', 'method' => 'handle'],
    ];

    $new = differBaseIndex();
    $new['events'][0]['handled_by'] = [
        ['listener' => 'App\\Listeners\\C', 'method' => 'handle'],
        ['listener' => 'App\\Listeners\\A', 'method' => 'handle'],
        // B removed, D added.
        ['listener' => 'App\\Listeners\\D', 'method' => 'handle'],
    ];

    $canonical = differ()->diff($old, $new);

    $newReordered = $new;
    $newReordered['events'][0]['handled_by'] = array_reverse($newReordered['events'][0]['handled_by']);

    expectSameDiff(differ()->diff($old, $newReordered), $canonical);
});

// -----------------------------------------------------------------------------
// Metadata churn ignored (critical)
// -----------------------------------------------------------------------------

it('ignores metadata-only differences', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['scanned_at'] = '2099-12-31T23:59:59+00:00';
    $new['loom_version'] = '9.9.9';
    $new['laravel_version'] = '99.x';
    $new['stats'] = ['events' => 999, 'listeners' => 999, 'noise' => true];

    $result = differ()->diff($old, $new);

    expect($result->hasChanges())->toBeFalse();
});

// -----------------------------------------------------------------------------
// events
// -----------------------------------------------------------------------------

it('detects an added event', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['events'][] = [
        'fqcn' => 'App\\Events\\NewEvent',
        'kind' => 'event',
        'file' => 'app/Events/NewEvent.php',
        'line' => 1,
        'handled_by' => [],
        'dispatched_from' => [],
    ];

    $diff = differ()->diff($old, $new)->sections()['events'];

    expect($diff->added)->toHaveCount(1);
    expect($diff->added[0]['fqcn'])->toBe('App\\Events\\NewEvent');
    expect($diff->removed)->toBe([]);
    expect($diff->changed)->toBe([]);
});

it('detects a removed event', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['events'] = [];

    $diff = differ()->diff($old, $new)->sections()['events'];

    expect($diff->removed)->toHaveCount(1);
    expect($diff->removed[0]['fqcn'])->toBe('App\\Events\\OrderPlaced');
    expect($diff->added)->toBe([]);
});

it('detects a changed event via file/line move', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['events'][0]['file'] = 'app/Domain/OrderPlaced.php';
    $new['events'][0]['line'] = 99;

    $diff = differ()->diff($old, $new)->sections()['events'];

    expect($diff->changed)->toHaveCount(1);
    $fields = array_map(static fn (FieldChange $c): string => $c->field, $diff->changed[0]->fieldChanges);
    expect($fields)->toBe(['file', 'line']);
});

it('detects a handled_by member added and removed as a sublist delta', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['events'][0]['handled_by'] = [
        ['listener' => 'App\\Listeners\\Other', 'method' => 'handle'],
    ];

    $diff = differ()->diff($old, $new)->sections()['events'];

    expect($diff->changed)->toHaveCount(1);
    $deltas = $diff->changed[0]->subListDeltas;
    expect($deltas)->toHaveCount(1);
    expect($deltas[0]->field)->toBe('handled_by');
    expect($deltas[0]->added)->toBe([['listener' => 'App\\Listeners\\Other', 'method' => 'handle']]);
    expect($deltas[0]->removed)->toBe([['listener' => 'App\\Listeners\\SendMail', 'method' => 'handle']]);
});

it('surfaces a dispatched_from overrides change as a member remove plus add', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    // Same file/line/method, but a different overrides payload: normalization
    // folds overrides into member identity, so this is remove+add, not edit.
    $new['events'][0]['dispatched_from'][0]['overrides'] = ['queue' => 'high'];

    $diff = differ()->diff($old, $new)->sections()['events'];

    expect($diff->changed)->toHaveCount(1);
    $delta = $diff->changed[0]->subListDeltas[0];
    expect($delta->field)->toBe('dispatched_from');
    expect($delta->added)->toHaveCount(1);
    expect($delta->removed)->toHaveCount(1);
    expect($delta->added[0]['overrides'])->toBe(['queue' => 'high']);
    expect($delta->removed[0]['overrides'])->toBe([]);
});

// -----------------------------------------------------------------------------
// listeners
// -----------------------------------------------------------------------------

it('reports a registration change as a field change (precedence shift)', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['listeners'][0]['registration'] = 'listen_array';

    $diff = differ()->diff($old, $new)->sections()['listeners'];

    expect($diff->changed)->toHaveCount(1);
    $change = $diff->changed[0]->fieldChanges[0];
    expect($change->field)->toBe('registration');
    expect($change->old)->toBe('auto_discovered');
    expect($change->new)->toBe('listen_array');
});

it('reports a handles method added and removed', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['listeners'][0]['handles'] = [
        ['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced'],
    ];

    $delta = differ()->diff($old, $new)->sections()['listeners']->changed[0]->subListDeltas[0];

    expect($delta->field)->toBe('handles');
    expect($delta->added)->toBe([['event' => 'App\\Events\\OrderPlaced', 'method' => 'onPlaced']]);
    expect($delta->removed)->toBe([['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']]);
});

it('reports a queued flip as a field change', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $new['listeners'][0]['queued'] = true;

    $change = differ()->diff($old, $new)->sections()['listeners']->changed[0]->fieldChanges[0];

    expect($change->field)->toBe('queued');
    expect($change->old)->toBeFalse();
    expect($change->new)->toBeTrue();
});

// -----------------------------------------------------------------------------
// observers
// -----------------------------------------------------------------------------

it('treats one FQCN observing two models as two distinct observer entries', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $observerOn = static fn (string $model): array => [
        'fqcn' => 'App\\Observers\\AuditObserver',
        'file' => 'app/Observers/AuditObserver.php',
        'line' => 5,
        'observes' => $model,
        'registration' => 'attribute',
        'hooks' => ['created'],
        'dispatches' => [],
    ];
    $old['observers'] = [$observerOn('App\\Models\\User')];
    $new['observers'] = [$observerOn('App\\Models\\User'), $observerOn('App\\Models\\Post')];

    $diff = differ()->diff($old, $new)->sections()['observers'];

    expect($diff->added)->toHaveCount(1);
    expect($diff->added[0]['observes'])->toBe('App\\Models\\Post');
    expect($diff->removed)->toBe([]);
    expect($diff->changed)->toBe([]);
});

it('reports observer hooks add and remove as a sublist delta', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $observer = [
        'fqcn' => 'App\\Observers\\UserObserver',
        'file' => 'app/Observers/UserObserver.php',
        'line' => 5,
        'observes' => 'App\\Models\\User',
        'registration' => 'attribute',
        'hooks' => ['created', 'updated'],
        'dispatches' => [],
    ];
    $old['observers'] = [$observer];
    $new['observers'] = [['hooks' => ['created', 'deleted']] + $observer];

    $delta = differ()->diff($old, $new)->sections()['observers']->changed[0]->subListDeltas[0];

    expect($delta->field)->toBe('hooks');
    expect($delta->added)->toBe(['deleted']);
    expect($delta->removed)->toBe(['updated']);
});

// -----------------------------------------------------------------------------
// jobs / mailables / notifications
// -----------------------------------------------------------------------------

it('reports a job queue_config null -> object as a field change', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $job = static fn (mixed $queueConfig): array => [
        'fqcn' => 'App\\Jobs\\ProcessOrder',
        'file' => 'app/Jobs/ProcessOrder.php',
        'line' => 12,
        'queued' => true,
        'queue_config' => $queueConfig,
        'dispatched_from' => [],
        'dispatches' => [],
    ];
    $old['jobs'] = [$job(null)];
    $new['jobs'] = [$job(['connection' => 'redis', 'queue' => 'high'])];

    $change = differ()->diff($old, $new)->sections()['jobs']->changed[0]->fieldChanges[0];

    expect($change->field)->toBe('queue_config');
    expect($change->old)->toBeNull();
    expect($change->new)->toBe(['connection' => 'redis', 'queue' => 'high']);
});

it('reports a notification channels reorder as a change (ordered compare)', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $notification = static fn (array $channels): array => [
        'fqcn' => 'App\\Notifications\\OrderShipped',
        'file' => 'app/Notifications/OrderShipped.php',
        'line' => 7,
        'queued' => true,
        'queue_config' => null,
        'notified_from' => [],
        'channels' => $channels,
        'channels_dynamic' => false,
    ];
    $old['notifications'] = [$notification(['mail', 'database'])];
    $new['notifications'] = [$notification(['database', 'mail'])];

    $change = differ()->diff($old, $new)->sections()['notifications']->changed[0]->fieldChanges[0];

    expect($change->field)->toBe('channels');
    expect($change->old)->toBe(['mail', 'database']);
    expect($change->new)->toBe(['database', 'mail']);
});

it('reports a notification channels_dynamic flip as a field change', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $notification = static fn (bool $dynamic): array => [
        'fqcn' => 'App\\Notifications\\OrderShipped',
        'file' => 'app/Notifications/OrderShipped.php',
        'line' => 7,
        'queued' => false,
        'queue_config' => null,
        'notified_from' => [],
        'channels' => ['mail'],
        'channels_dynamic' => $dynamic,
    ];
    $old['notifications'] = [$notification(false)];
    $new['notifications'] = [$notification(true)];

    $fields = array_map(
        static fn (FieldChange $c): string => $c->field,
        differ()->diff($old, $new)->sections()['notifications']->changed[0]->fieldChanges,
    );

    expect($fields)->toContain('channels_dynamic');
});

// -----------------------------------------------------------------------------
// unresolved_dispatches
// -----------------------------------------------------------------------------

it('reports an unresolved_dispatch reason change on the same (file,line,expression) as changed', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $entry = static fn (string $reason): array => [
        'file' => 'app/Services/Foo.php',
        'line' => 30,
        'expression' => 'event($e)',
        'reason' => $reason,
    ];
    $old['unresolved_dispatches'] = [$entry('dynamic_variable')];
    $new['unresolved_dispatches'] = [$entry('container_resolution')];

    $diff = differ()->diff($old, $new)->sections()['unresolved_dispatches'];

    expect($diff->added)->toBe([]);
    expect($diff->removed)->toBe([]);
    expect($diff->changed)->toHaveCount(1);
    expect($diff->changed[0]->fieldChanges[0]->field)->toBe('reason');
});

it('reports unresolved_dispatch as add+remove when (file,line,expression) differs', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $old['unresolved_dispatches'] = [
        ['file' => 'app/Services/Foo.php', 'line' => 30, 'expression' => 'event($e)', 'reason' => 'dynamic_variable'],
    ];
    $new['unresolved_dispatches'] = [
        ['file' => 'app/Services/Foo.php', 'line' => 31, 'expression' => 'event($e)', 'reason' => 'dynamic_variable'],
    ];

    $diff = differ()->diff($old, $new)->sections()['unresolved_dispatches'];

    expect($diff->added)->toHaveCount(1);
    expect($diff->removed)->toHaveCount(1);
    expect($diff->changed)->toBe([]);
});

// -----------------------------------------------------------------------------
// closure_listeners (add/remove only)
// -----------------------------------------------------------------------------

it('reports a closure listener event edit at the same line as remove plus add', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $closure = static fn (string $event): array => [
        'file' => 'routes/console.php',
        'line' => 20,
        'event' => $event,
        'registration' => 'event_listen_call',
        'end_line' => 22,
    ];
    $old['closure_listeners'] = [$closure('App\\Events\\OrderPlaced')];
    $new['closure_listeners'] = [$closure('App\\Events\\OrderShipped')];

    $diff = differ()->diff($old, $new)->sections()['closure_listeners'];

    expect($diff->added)->toHaveCount(1);
    expect($diff->removed)->toHaveCount(1);
    expect($diff->changed)->toBe([]);
});

// -----------------------------------------------------------------------------
// scheduled
// -----------------------------------------------------------------------------

it('identifies scheduled entries by (file,line,kind,target) including a null target', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $base = [
        'file' => 'routes/console.php',
        'line' => 5,
        'kind' => 'closure',
        'target' => null,
        'cron' => '0 0 * * *',
        'timezone' => null,
        'without_overlapping' => false,
        'on_one_server' => false,
        'run_in_background' => false,
        'constraints' => [],
    ];
    $old['scheduled'] = [$base];
    // Same null-target identity, cron changed.
    $new['scheduled'] = [['cron' => '0 1 * * *'] + $base];

    $diff = differ()->diff($old, $new)->sections()['scheduled'];

    expect($diff->added)->toBe([]);
    expect($diff->removed)->toBe([]);
    expect($diff->changed)->toHaveCount(1);
    expect($diff->changed[0]->fieldChanges[0]->field)->toBe('cron');
});

it('reports a scheduled constraints member add and remove as a sublist delta', function () {
    $old = differBaseIndex();
    $new = differBaseIndex();
    $base = [
        'file' => 'routes/console.php',
        'line' => 5,
        'kind' => 'command',
        'target' => 'backup:run',
        'cron' => '0 0 * * *',
        'timezone' => null,
        'without_overlapping' => false,
        'on_one_server' => false,
        'run_in_background' => false,
        'constraints' => ['weekdays()', 'at(02:00)'],
    ];
    $old['scheduled'] = [$base];
    $new['scheduled'] = [['constraints' => ['weekdays()', 'at(03:00)']] + $base];

    $delta = differ()->diff($old, $new)->sections()['scheduled']->changed[0]->subListDeltas[0];

    expect($delta->field)->toBe('constraints');
    expect($delta->added)->toBe(['at(03:00)']);
    expect($delta->removed)->toBe(['at(02:00)']);
});

// -----------------------------------------------------------------------------
// Injectable comparator
// -----------------------------------------------------------------------------

it('uses the registry spec order for the section keys', function () {
    $result = differ()->diff(differBaseIndex(), differBaseIndex());

    expect(array_keys($result->sections()))->toBe([
        'events',
        'model_events',
        'listeners',
        'observers',
        'jobs',
        'unresolved_dispatches',
        'closure_listeners',
        'scheduled',
        'mailables',
        'notifications',
    ]);
});
