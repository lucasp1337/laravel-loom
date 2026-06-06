<?php

declare(strict_types=1);

/**
 * A section-complete (but metadata-light) index, written to disk for the
 * command to read. Only the section bodies drive the diff.
 *
 * @return array<string,mixed>
 */
function diffCommandIndex(): array
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
                'dispatched_from' => [],
            ],
        ],
        'listeners' => [
            [
                'fqcn' => 'App\\Listeners\\SendMail',
                'file' => 'app/Listeners/SendMail.php',
                'line' => 8,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']],
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

/**
 * Write a value to a fresh temp file and register it for cleanup.
 */
function diffTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'loom-diff-');
    expect($path)->not->toBeFalse();
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  array<string,mixed>  $index
 */
function diffTempIndex(array $index): string
{
    return diffTempFile((string) json_encode($index));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/loom-diff-*') ?: [] as $file) {
        @unlink($file);
    }
});

// -----------------------------------------------------------------------------
// Exit codes
// -----------------------------------------------------------------------------

it('exits 0 when two identical index files are diffed', function () {
    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b])
        ->assertExitCode(0);
});

it('exits 1 when the two index files differ semantically', function () {
    $new = diffCommandIndex();
    $new['listeners'][0]['queued'] = true;

    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex($new);

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b])
        ->assertExitCode(1);
});

it('exits 2 when the old path does not exist', function () {
    $b = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => '/no/such/old.json', 'new' => $b])
        ->assertExitCode(2);
});

it('exits 2 when the new path does not exist', function () {
    $a = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => $a, 'new' => '/no/such/new.json'])
        ->assertExitCode(2);
});

it('exits 2 when a file contains malformed JSON', function () {
    $a = diffTempIndex(diffCommandIndex());
    $bad = diffTempFile('{not valid json');

    $this->artisan('loom:diff', ['old' => $a, 'new' => $bad])
        ->assertExitCode(2);
});

it('exits 2 when a file decodes to a non-array top level', function () {
    $a = diffTempIndex(diffCommandIndex());
    $scalar = diffTempFile('42');

    $this->artisan('loom:diff', ['old' => $a, 'new' => $scalar])
        ->assertExitCode(2);
});

it('exits 2 for an unknown --format', function () {
    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b, '--format' => 'bogus'])
        ->assertExitCode(2);
});

// -----------------------------------------------------------------------------
// Formatter output
// -----------------------------------------------------------------------------

it('prints a no-changes line and exits 0 for the default text format when identical', function () {
    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b])
        ->expectsOutputToContain('No semantic changes.')
        ->assertExitCode(0);
});

it('prints {} and exits 0 for the json format when identical', function () {
    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex(diffCommandIndex());

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b, '--format' => 'json'])
        ->expectsOutputToContain('{}')
        ->assertExitCode(0);
});

it('prints change markers in the default text output when entries change', function () {
    $new = diffCommandIndex();
    $new['events'][0]['line'] = 99;

    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex($new);

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b])
        ->expectsOutputToContain('~')
        ->assertExitCode(1);
});

it('emits valid, parseable JSON for the json format when entries change', function () {
    $new = diffCommandIndex();
    $new['listeners'][0]['queued'] = true;

    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex($new);

    // Drive the command through the kernel and capture its real stdout so we
    // can parse the JSON back. Bypasses the console-output mock that the
    // PendingCommand expectation API installs.
    $this->withoutMockingConsoleOutput();

    $exitCode = $this->artisan('loom:diff', ['old' => $a, 'new' => $b, '--format' => 'json']);

    $printed = trim($this->app[\Illuminate\Contracts\Console\Kernel::class]->output());

    expect($exitCode)->toBe(1);

    $decoded = json_decode($printed, true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('listeners');
});

it('emits section headers for the markdown format when entries change', function () {
    $new = diffCommandIndex();
    $new['listeners'][0]['queued'] = true;

    $a = diffTempIndex(diffCommandIndex());
    $b = diffTempIndex($new);

    $this->artisan('loom:diff', ['old' => $a, 'new' => $b, '--format' => 'markdown'])
        ->expectsOutputToContain('## listeners')
        ->assertExitCode(1);
});
