<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\JobsScanner;

function jobsFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/jobs-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function jobByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('returns an empty jobs array when neither app/Jobs nor app/ exist', function () {
    $entries = (new JobsScanner)->scan(sys_get_temp_dir())['jobs'];

    expect($entries)->toBe([]);
});

it('discovers the expected set of jobs from the fixture app', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $fqcns = array_column($entries, 'fqcn');

    expect($fqcns)->toContain('App\\Jobs\\ProcessOrder');
    expect($fqcns)->toContain('App\\Jobs\\SendInvoice');
    expect($fqcns)->toContain('App\\Jobs\\RunReport');
    expect($fqcns)->toContain('App\\Domain\\Billing\\Jobs\\ChargeCustomer');
    expect($entries)->toHaveCount(4);
});

it('skips abstract job classes', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    expect(jobByFqcn($entries, 'App\\Jobs\\AbstractJob'))->toBeNull();
});

it('emits each entry with all required top-level keys', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['fqcn', 'file', 'line', 'queued', 'queue_config', 'dispatched_from', 'dispatches']);
        expect($entry['dispatched_from'])->toBe([]);
        expect($entry['dispatches'])->toBe([]);
    }
});

it('records queued=true and a populated queue_config for ProcessOrder', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\ProcessOrder');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => 'redis',
        'queue' => 'high',
        'delay' => 30,
        'tries' => 5,
        'timeout' => 120,
        'backoff' => 10,
    ]);
    expect($entry['file'])->toBe('app/Jobs/ProcessOrder.php');
});

it('records queued=true and a partial queue_config for SendInvoice', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\SendInvoice');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => null,
        'queue' => 'invoices',
        'delay' => null,
        'tries' => 3,
        'timeout' => null,
        'backoff' => null,
    ]);
});

it('records queued=false and queue_config=null for RunReport', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\RunReport');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeFalse();
    expect($entry['queue_config'])->toBeNull();
});

it('discovers ChargeCustomer outside app/Jobs/ via dispatch-site seeding', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Domain\\Billing\\Jobs\\ChargeCustomer');

    expect($entry)->not->toBeNull();
    expect($entry['file'])->toBe('app/Domain/Billing/Jobs/ChargeCustomer.php');
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => 'sqs',
        'queue' => 'billing',
        'delay' => null,
        'tries' => null,
        'timeout' => null,
        'backoff' => null,
    ]);
});

it('sorts job entries by FQCN ascending', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $fqcns = array_column($entries, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    foreach ($entries as $entry) {
        expect($entry['file'])->not->toContain('\\');
        expect($entry['file'])->toStartWith('app/');
    }
});
