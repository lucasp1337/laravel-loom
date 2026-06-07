<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

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
        'stats' => [
            'events' => 1, 'listeners' => 0, 'observers' => 0, 'jobs' => 0,
            'unresolved_dispatches' => 0, 'closure_listeners' => 0,
            'scheduled' => 0, 'mailables' => 0, 'notifications' => 0,
            'routes' => 0,
        ],
    ]);
    $path = checkTempIndex($index);

    $this->artisan('loom:check', ['index' => $path, '--skip' => ['orphan-events']])
        ->assertExitCode(0);
});
