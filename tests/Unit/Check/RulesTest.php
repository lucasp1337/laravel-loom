<?php

declare(strict_types=1);

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\Result\Violation;
use Lucasp\Loom\Check\Rules\CyclicDispatchRule;
use Lucasp\Loom\Check\Rules\OrphanEventsRule;
use Lucasp\Loom\Check\Rules\OrphanListenersRule;
use Lucasp\Loom\Check\Rules\SchemaValidationRule;
use Lucasp\Loom\Check\Rules\UnresolvedDispatchesRule;

/**
 * A minimal, schema-valid index. Sections may be overridden per test.
 *
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function checkValidIndex(array $overrides = []): array
{
    $base = [
        'loom_version' => '0.3.0',
        'laravel_version' => '12.x',
        'scanned_at' => '2026-01-01T00:00:00+00:00',
        'stats' => [
            'events' => 1, 'listeners' => 1, 'observers' => 0, 'jobs' => 0,
            'unresolved_dispatches' => 0, 'closure_listeners' => 0,
            'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
            'routes' => 0,
        ],
        'events' => [[
            'id' => 'App\\Events\\OrderPlaced',
            'fqcn' => 'App\\Events\\OrderPlaced',
            'kind' => 'class',
            'file' => 'app/Events/OrderPlaced.php',
            'line' => 10,
            'handled_by' => [['listener' => 'App\\Listeners\\SendMail', 'method' => 'handle']],
            'dispatched_from' => [['file' => 'app/Services/Checkout.php', 'line' => 5, 'method' => 'dispatch']],
        ]],
        'listeners' => [[
            'fqcn' => 'App\\Listeners\\SendMail',
            'file' => 'app/Listeners/SendMail.php',
            'line' => 8,
            'registration' => 'auto_discovered',
            'queued' => false,
            'handles' => [['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']],
            'dispatches' => [],
        ]],
        'observers' => [],
        'model_events' => [],
        'jobs' => [],
        'unresolved_dispatches' => [],
        'closure_listeners' => [],
        'scheduled' => [],
        'mailables' => [],
        'notifications' => [],
        'routes' => [],
    ];

    return array_replace($base, $overrides);
}

/**
 * @param  array<string,mixed>  $index
 */
function checkContext(array $index, ?array $baseline = null, bool $strict = false): CheckContext
{
    return new CheckContext($index, $baseline, $strict);
}

// -----------------------------------------------------------------------------
// OrphanListenersRule
// -----------------------------------------------------------------------------

describe('OrphanListenersRule', function () {
    it('flags a listener that handles no events', function () {
        $index = checkValidIndex([
            'listeners' => [['fqcn' => 'App\\Listeners\\Idle', 'handles' => []]],
        ]);

        $violations = (new OrphanListenersRule)->check(checkContext($index));

        expect($violations)->toHaveCount(1);
        expect($violations[0]->message)->toBe('Listener App\\Listeners\\Idle handles no events.');
    });

    it('does not flag a listener with handles', function () {
        $violations = (new OrphanListenersRule)->check(checkContext(checkValidIndex()));

        expect($violations)->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// OrphanEventsRule
// -----------------------------------------------------------------------------

describe('OrphanEventsRule', function () {
    it('flags an event that is neither dispatched nor handled', function () {
        $index = checkValidIndex([
            'events' => [[
                'fqcn' => 'App\\Events\\Lonely',
                'handled_by' => [],
                'dispatched_from' => [],
            ]],
        ]);

        $violations = (new OrphanEventsRule)->check(checkContext($index));

        expect($violations)->toHaveCount(1);
        expect($violations[0]->message)->toBe('Event App\\Events\\Lonely is never dispatched and never handled.');
    });

    it('does not flag an event with only handlers', function () {
        $index = checkValidIndex([
            'events' => [[
                'fqcn' => 'App\\Events\\Handled',
                'handled_by' => [['listener' => 'App\\Listeners\\X', 'method' => 'handle']],
                'dispatched_from' => [],
            ]],
        ]);

        expect((new OrphanEventsRule)->check(checkContext($index)))->toBe([]);
    });

    it('does not flag an event with only a dispatch site', function () {
        $index = checkValidIndex([
            'events' => [[
                'fqcn' => 'App\\Events\\Dispatched',
                'handled_by' => [],
                'dispatched_from' => [['file' => 'app/X.php', 'line' => 1, 'method' => 'dispatch']],
            ]],
        ]);

        expect((new OrphanEventsRule)->check(checkContext($index)))->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// UnresolvedDispatchesRule
// -----------------------------------------------------------------------------

describe('UnresolvedDispatchesRule', function () {
    $entries = static fn (): array => [
        ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'],
        ['file' => 'app/B.php', 'line' => 20, 'expression' => 'event($b)', 'reason' => 'container_resolution'],
    ];

    it('flags every entry in strict mode', function () use ($entries) {
        $index = checkValidIndex(['unresolved_dispatches' => $entries()]);

        $violations = (new UnresolvedDispatchesRule)->check(checkContext($index, strict: true));

        expect($violations)->toHaveCount(2);
    });

    it('flags only entries absent from the baseline', function () use ($entries) {
        $index = checkValidIndex(['unresolved_dispatches' => $entries()]);
        // Baseline already knows the first entry; only the second is new.
        $baseline = checkValidIndex([
            'unresolved_dispatches' => [
                ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'],
            ],
        ]);

        $violations = (new UnresolvedDispatchesRule)->check(checkContext($index, $baseline));

        expect($violations)->toHaveCount(1);
        expect($violations[0]->context['file'])->toBe('app/B.php');
    });

    it('flags nothing without a baseline in non-strict mode', function () use ($entries) {
        $index = checkValidIndex(['unresolved_dispatches' => $entries()]);

        expect((new UnresolvedDispatchesRule)->check(checkContext($index)))->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// SchemaValidationRule
// -----------------------------------------------------------------------------

describe('SchemaValidationRule', function () {
    it('reports no violations for a schema-valid index', function () {
        $violations = (new SchemaValidationRule)->check(checkContext(checkValidIndex()));

        expect($violations)->toBe([]);
    });

    it('reports at least one violation for a schema-invalid index', function () {
        // Drop required top-level sections.
        $index = checkValidIndex();
        unset($index['stats'], $index['notifications']);

        $violations = (new SchemaValidationRule)->check(checkContext($index));

        expect(count($violations))->toBeGreaterThan(0);
    });
});

// -----------------------------------------------------------------------------
// CyclicDispatchRule
// -----------------------------------------------------------------------------

describe('CyclicDispatchRule', function () {
    it('flags a cyclic dispatch chain with the cycle context', function () {
        $index = checkValidIndex([
            'events' => [
                [
                    'id' => 'App\\Events\\A', 'fqcn' => 'App\\Events\\A', 'kind' => 'class',
                    'file' => 'app/A.php', 'line' => 1,
                    'handled_by' => [['listener' => 'App\\Listeners\\La', 'method' => 'handle']],
                    'dispatched_from' => [],
                ],
                [
                    'id' => 'App\\Events\\B', 'fqcn' => 'App\\Events\\B', 'kind' => 'class',
                    'file' => 'app/B.php', 'line' => 1,
                    'handled_by' => [['listener' => 'App\\Listeners\\Lb', 'method' => 'handle']],
                    'dispatched_from' => [],
                ],
            ],
            'listeners' => [
                [
                    'fqcn' => 'App\\Listeners\\La', 'file' => 'app/La.php', 'line' => 1,
                    'registration' => 'auto_discovered', 'queued' => false, 'handles' => [],
                    'dispatches' => [['target' => 'App\\Events\\B', 'kind' => 'event', 'confidence' => 'high', 'file' => 'app/La.php', 'line' => 2]],
                ],
                [
                    'fqcn' => 'App\\Listeners\\Lb', 'file' => 'app/Lb.php', 'line' => 1,
                    'registration' => 'auto_discovered', 'queued' => false, 'handles' => [],
                    'dispatches' => [['target' => 'App\\Events\\A', 'kind' => 'event', 'confidence' => 'high', 'file' => 'app/Lb.php', 'line' => 2]],
                ],
            ],
        ]);

        $violations = (new CyclicDispatchRule)->check(checkContext($index));

        expect($violations)->toHaveCount(1);
        expect($violations[0])->toBeInstanceOf(Violation::class);
        expect($violations[0]->message)->toStartWith('Cyclic dispatch: ');
        expect($violations[0]->message)->toContain("\u{2192}");
        expect($violations[0]->context)->toHaveKey('cycle');
        expect($violations[0]->context['cycle'])->toBe(['App\\Events\\A', 'App\\Events\\B', 'App\\Events\\A']);
    });

    it('reports no violations for an acyclic index', function () {
        expect((new CyclicDispatchRule)->check(checkContext(checkValidIndex())))->toBe([]);
    });
});
