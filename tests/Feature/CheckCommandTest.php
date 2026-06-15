<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Lucasp\Loom\Check\RuleKey;

/**
 * Stats block with every counter zeroed. Pass overrides for the few that matter
 * to a given test; the rest stay at zero so the schema stays satisfied.
 *
 * @param  array<string,int>  $overrides
 * @return array<string,int>
 */
function checkCommandStats(array $overrides = []): array
{
    return array_replace([
        'events' => 0, 'listeners' => 0, 'observers' => 0, 'jobs' => 0,
        'unresolved_dispatches' => 0, 'closure_listeners' => 0,
        'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
        'routes' => 0,
    ], $overrides);
}

/**
 * A minimal, schema-valid index. Sections may be overridden per test.
 *
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function checkCommandIndex(array $overrides = []): array
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

function checkTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'loom-check-');
    expect($path)->not->toBeFalse();
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  array<string,mixed>  $index
 */
function checkTempIndex(array $index): string
{
    return checkTempFile((string) json_encode($index));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/loom-check-*') ?: [] as $file) {
        @unlink($file);
    }

    // Clean up the default-path fixture if a test wrote one.
    $default = $this->app->storagePath('loom/index.json');
    if (is_file($default)) {
        @unlink($default);
    }
});

// -----------------------------------------------------------------------------
// Exit code 0: clean run
// -----------------------------------------------------------------------------

it('exits 0 and reports all checks passed for a clean valid index', function () {
    $path = checkTempIndex(checkCommandIndex());

    $this->artisan('loom:check', ['index' => $path])
        ->expectsOutputToContain('All checks passed.')
        ->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// Exit code 1: violations
// -----------------------------------------------------------------------------

it('exits 1 when an event is orphaned', function () {
    $index = checkCommandIndex([
        'events' => [[
            'id' => 'App\\Events\\Lonely',
            'fqcn' => 'App\\Events\\Lonely',
            'kind' => 'class',
            'file' => 'app/Events/Lonely.php',
            'line' => 3,
            'handled_by' => [],
            'dispatched_from' => [],
        ]],
        'listeners' => [],
        'stats' => [
            'events' => 1, 'listeners' => 0, 'observers' => 0, 'jobs' => 0,
            'unresolved_dispatches' => 0, 'closure_listeners' => 0,
            'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
            'routes' => 0,
        ],
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path])
        ->assertExitCode(1);
});

it('exits 1 in strict mode with an unresolved dispatch', function () {
    $index = checkCommandIndex([
        'unresolved_dispatches' => [
            ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'],
        ],
        'stats' => [
            'events' => 1, 'listeners' => 1, 'observers' => 0, 'jobs' => 0,
            'unresolved_dispatches' => 1, 'closure_listeners' => 0,
            'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
            'routes' => 0,
        ],
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path, '--strict' => true])
        ->assertExitCode(1);
});

it('does not fail on the same unresolved dispatch without strict mode', function () {
    $index = checkCommandIndex([
        'unresolved_dispatches' => [
            ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'],
        ],
        'stats' => [
            'events' => 1, 'listeners' => 1, 'observers' => 0, 'jobs' => 0,
            'unresolved_dispatches' => 1, 'closure_listeners' => 0,
            'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
            'routes' => 0,
        ],
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path])
        ->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// Default index path
// -----------------------------------------------------------------------------

it('reads the default storage path when no index argument is given', function () {
    $default = $this->app->storagePath('loom/index.json');
    if (! is_dir(dirname($default))) {
        mkdir(dirname($default), 0o777, true);
    }
    file_put_contents($default, (string) json_encode(checkCommandIndex()));

    $this->artisan('loom:check')
        ->expectsOutputToContain('All checks passed.')
        ->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// Exit code 2: invocation errors
// -----------------------------------------------------------------------------

it('exits 2 when the index path does not exist', function () {
    $this->artisan('loom:check', ['index' => '/no/such/index.json'])
        ->assertExitCode(2);
});

it('exits 2 when the index file is malformed JSON', function () {
    $path = checkTempFile('{not valid json');

    $this->artisan('loom:check', ['index' => $path])
        ->assertExitCode(2);
});

it('exits 2 for an unknown --format', function () {
    $path = checkTempIndex(checkCommandIndex());

    $this->artisan('loom:check', ['index' => $path, '--format' => 'bogus'])
        ->assertExitCode(2);
});

it('exits 2 for an unknown --skip key', function () {
    $path = checkTempIndex(checkCommandIndex());

    $this->artisan('loom:check', ['index' => $path, '--skip' => ['not-a-rule']])
        ->assertExitCode(2);
});

it('exits 2 when the baseline path does not exist', function () {
    $path = checkTempIndex(checkCommandIndex());

    $this->artisan('loom:check', ['index' => $path, '--baseline' => '/no/such/baseline.json'])
        ->assertExitCode(2);
});

// -----------------------------------------------------------------------------
// Formats
// -----------------------------------------------------------------------------

it('emits parseable JSON with a passed key for the json format', function () {
    $path = checkTempIndex(checkCommandIndex());

    $this->withoutMockingConsoleOutput();

    $exitCode = $this->artisan('loom:check', ['index' => $path, '--format' => 'json']);

    $printed = trim($this->app[Kernel::class]->output());

    expect($exitCode)->toBe(0);

    $decoded = json_decode($printed, true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('passed');
    expect($decoded['passed'])->toBeTrue();
});

it('emits a markdown heading for the markdown format on a failing index', function () {
    $index = checkCommandIndex([
        'listeners' => [[
            'fqcn' => 'App\\Listeners\\Idle',
            'file' => 'app/Listeners/Idle.php',
            'line' => 1,
            'registration' => 'auto_discovered',
            'queued' => false,
            'handles' => [],
            'dispatches' => [],
        ]],
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path, '--format' => 'markdown'])
        ->expectsOutputToContain('## loom:check')
        ->assertExitCode(1);
});

// -----------------------------------------------------------------------------
// Skip suppresses a failure
// -----------------------------------------------------------------------------

it('passes when --skip suppresses the only failing rule', function () {
    $index = checkCommandIndex([
        'events' => [[
            'id' => 'App\\Events\\Lonely',
            'fqcn' => 'App\\Events\\Lonely',
            'kind' => 'class',
            'file' => 'app/Events/Lonely.php',
            'line' => 3,
            'handled_by' => [],
            'dispatched_from' => [],
        ]],
        'listeners' => [],
        'stats' => checkCommandStats(['events' => 1]),
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path, '--skip' => [RuleKey::ORPHAN_EVENTS->value]])
        ->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// Index builders for the dedicated rule scenarios below
// -----------------------------------------------------------------------------

/**
 * An index whose only listener handles no events (orphan listener), with no
 * events present so no other section-level rule can fire.
 *
 * @return array<string,mixed>
 */
function orphanListenerIndex(): array
{
    return checkCommandIndex([
        'events' => [],
        'listeners' => [[
            'fqcn' => 'App\\Listeners\\Idle',
            'file' => 'app/Listeners/Idle.php',
            'line' => 1,
            'registration' => 'auto_discovered',
            'queued' => false,
            'handles' => [],
            'dispatches' => [],
        ]],
        'stats' => checkCommandStats(['listeners' => 1]),
    ]);
}

/**
 * A two-node dispatch cycle: event A is handled by a listener that dispatches
 * event B, and event B is handled by a listener that dispatches event A. Every
 * event is handled and every listener handles an event, so only the cyclic
 * rule has anything to flag.
 *
 * @return array<string,mixed>
 */
function cyclicDispatchIndex(): array
{
    $dispatch = static fn (string $target): array => [
        'target' => $target,
        'kind' => 'event',
        'confidence' => 'high',
        'file' => 'app/Listeners/Loop.php',
        'line' => 12,
    ];

    return checkCommandIndex([
        'events' => [
            [
                'id' => 'App\\Events\\A',
                'fqcn' => 'App\\Events\\A',
                'kind' => 'class',
                'file' => 'app/Events/A.php',
                'line' => 5,
                'handled_by' => [['listener' => 'App\\Listeners\\HandleA', 'method' => 'handle']],
                'dispatched_from' => [],
            ],
            [
                'id' => 'App\\Events\\B',
                'fqcn' => 'App\\Events\\B',
                'kind' => 'class',
                'file' => 'app/Events/B.php',
                'line' => 5,
                'handled_by' => [['listener' => 'App\\Listeners\\HandleB', 'method' => 'handle']],
                'dispatched_from' => [],
            ],
        ],
        'listeners' => [
            [
                'fqcn' => 'App\\Listeners\\HandleA',
                'file' => 'app/Listeners/HandleA.php',
                'line' => 8,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [['event' => 'App\\Events\\A', 'method' => 'handle']],
                'dispatches' => [$dispatch('App\\Events\\B')],
            ],
            [
                'fqcn' => 'App\\Listeners\\HandleB',
                'file' => 'app/Listeners/HandleB.php',
                'line' => 8,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [['event' => 'App\\Events\\B', 'method' => 'handle']],
                'dispatches' => [$dispatch('App\\Events\\A')],
            ],
        ],
        'stats' => checkCommandStats(['events' => 2, 'listeners' => 2]),
    ]);
}

// -----------------------------------------------------------------------------
// Baseline-based growth detection (the real --no-new-unresolved behavior)
// -----------------------------------------------------------------------------

it('exits 1 when a new unresolved dispatch is absent from the baseline', function () {
    $kept = ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'];
    $added = ['file' => 'app/B.php', 'line' => 22, 'expression' => 'dispatch($job)', 'reason' => 'dynamic_class_name'];

    $baseline = checkCommandIndex([
        'unresolved_dispatches' => [$kept],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 1]),
    ]);
    $current = checkCommandIndex([
        'unresolved_dispatches' => [$kept, $added],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 2]),
    ]);

    // Assert via JSON: the text formatter's colorized line is wrapped at the
    // terminal width, which can split the expression substring; JSON keeps each
    // field on its own short, unwrapped line.
    $this->artisan('loom:check', [
        'index' => checkTempIndex($current),
        '--baseline' => checkTempIndex($baseline),
        '--format' => 'json',
    ])
        // `dispatch($job)` belongs only to the added entry (absent from the
        // baseline), so flagging it proves the new-unresolved detection.
        ->expectsOutputToContain('"expression": "dispatch($job)"')
        ->assertExitCode(1);
});

it('exits 0 when current unresolved dispatches are a subset of the baseline', function () {
    $a = ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'];
    $b = ['file' => 'app/B.php', 'line' => 22, 'expression' => 'dispatch($job)', 'reason' => 'dynamic_class_name'];

    $baseline = checkCommandIndex([
        'unresolved_dispatches' => [$a, $b],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 2]),
    ]);
    $current = checkCommandIndex([
        'unresolved_dispatches' => [$a],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 1]),
    ]);

    $this->artisan('loom:check', [
        'index' => checkTempIndex($current),
        '--baseline' => checkTempIndex($baseline),
    ])->assertExitCode(0);
});

it('fails under strict even when the unresolved dispatch is in the baseline', function () {
    $entry = ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'];

    $baseline = checkCommandIndex([
        'unresolved_dispatches' => [$entry],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 1]),
    ]);
    $current = checkCommandIndex([
        'unresolved_dispatches' => [$entry],
        'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 1]),
    ]);

    $this->artisan('loom:check', [
        'index' => checkTempIndex($current),
        '--baseline' => checkTempIndex($baseline),
        '--strict' => true,
    ])->assertExitCode(1);
});

// -----------------------------------------------------------------------------
// Orphan listeners rule (dedicated)
// -----------------------------------------------------------------------------

it('exits 1 when a listener handles no events', function () {
    $this->artisan('loom:check', ['index' => checkTempIndex(orphanListenerIndex())])
        ->expectsOutputToContain('App\\Listeners\\Idle')
        ->assertExitCode(1);
});

it('exits 0 when every listener handles at least one event', function () {
    // The base index ships a single listener that handles OrderPlaced.
    $this->artisan('loom:check', ['index' => checkTempIndex(checkCommandIndex())])
        ->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// Cyclic dispatch rule
// -----------------------------------------------------------------------------

it('exits 1 when a dispatch cycle exists between two events', function () {
    $this->artisan('loom:check', ['index' => checkTempIndex(cyclicDispatchIndex())])
        ->expectsOutputToContain('Cyclic dispatch')
        ->assertExitCode(1);
});

it('exits 0 when the cyclic-dispatch rule is skipped', function () {
    $this->artisan('loom:check', [
        'index' => checkTempIndex(cyclicDispatchIndex()),
        '--skip' => [RuleKey::CYCLIC_DISPATCH->value],
    ])->assertExitCode(0);
});

// -----------------------------------------------------------------------------
// --skip removes exactly the targeted rule's failure
// -----------------------------------------------------------------------------

/**
 * Each case pairs a single-failure index with the RuleKey that should suppress
 * it. Skipping that key must drop the run to a clean pass.
 *
 * @return iterable<string,array{0:array<string,mixed>,1:RuleKey}>
 */
dataset('single failing rule', function () {
    yield 'orphan-events' => [
        checkCommandIndex([
            'events' => [[
                'id' => 'App\\Events\\Lonely',
                'fqcn' => 'App\\Events\\Lonely',
                'kind' => 'class',
                'file' => 'app/Events/Lonely.php',
                'line' => 3,
                'handled_by' => [],
                'dispatched_from' => [],
            ]],
            'listeners' => [],
            'stats' => checkCommandStats(['events' => 1]),
        ]),
        RuleKey::ORPHAN_EVENTS,
    ];

    yield 'orphan-listeners' => [orphanListenerIndex(), RuleKey::ORPHAN_LISTENERS];

    yield 'cyclic-dispatch' => [cyclicDispatchIndex(), RuleKey::CYCLIC_DISPATCH];

    yield 'unresolved-dispatches (strict)' => [
        checkCommandIndex([
            'unresolved_dispatches' => [
                ['file' => 'app/A.php', 'line' => 10, 'expression' => 'event($a)', 'reason' => 'dynamic_class_name'],
            ],
            'stats' => checkCommandStats(['events' => 1, 'listeners' => 1, 'unresolved_dispatches' => 1]),
        ]),
        RuleKey::UNRESOLVED_DISPATCHES,
    ];

    yield 'schema' => [
        // A listener missing required fields trips schema validation; it still
        // handles an event so orphan-listeners stays silent.
        checkCommandIndex([
            'listeners' => [[
                'fqcn' => 'App\\Listeners\\Broken',
                'handles' => [['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']],
            ]],
            'stats' => checkCommandStats(['events' => 1, 'listeners' => 1]),
        ]),
        RuleKey::SCHEMA,
    ];
});

it('fails without the skip and passes once the failing rule is skipped', function (array $index, RuleKey $key) {
    $path = checkTempIndex($index);

    $strict = $key === RuleKey::UNRESOLVED_DISPATCHES;

    $without = ['index' => $path];
    if ($strict) {
        $without['--strict'] = true;
    }
    $this->artisan('loom:check', $without)->assertExitCode(1);

    $with = ['index' => $path, '--skip' => [$key->value]];
    if ($strict) {
        $with['--strict'] = true;
    }
    $this->artisan('loom:check', $with)->assertExitCode(0);
})->with('single failing rule');
