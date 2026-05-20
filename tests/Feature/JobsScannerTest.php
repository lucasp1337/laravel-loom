<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\JobEntry;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Scanners\JobsScanner;

function jobsFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/jobs-fixture-app';
}

/**
 * @param  list<JobEntry>  $entries
 */
function jobByFqcn(array $entries, string $fqcn): ?JobEntry
{
    foreach ($entries as $entry) {
        if ($entry->fqcn === $fqcn) {
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

    $fqcns = array_map(fn (JobEntry $e): string => $e->fqcn, $entries);

    expect($fqcns)->toContain('App\\Jobs\\ProcessOrder');
    expect($fqcns)->toContain('App\\Jobs\\SendInvoice');
    expect($fqcns)->toContain('App\\Jobs\\RunReport');
    expect($fqcns)->toContain('App\\Jobs\\IndirectlyQueued');
    expect($fqcns)->toContain('App\\Domain\\Billing\\Jobs\\ChargeCustomer');
    expect($entries)->toHaveCount(5);
});

it('detects indirect ShouldQueue via a parent abstract class', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\IndirectlyQueued');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: null,
        queue: 'reports',
        delay: null,
        tries: null,
        timeout: null,
        backoff: null,
    ));
});

it('skips abstract job classes', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    expect(jobByFqcn($entries, 'App\\Jobs\\AbstractJob'))->toBeNull();
});

it('emits each entry as a JobEntry DTO carrying every schema field', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(JobEntry::class);
        expect($entry->fqcn)->toBeString();
        expect($entry->file)->toBeString();
        expect($entry->line)->toBeInt();
        expect($entry->queued)->toBeBool();
        // queue_config is null when queued is false (per schema oneOf).
        if ($entry->queued) {
            expect($entry->queueConfig)->toBeInstanceOf(QueueConfigData::class);
        } else {
            expect($entry->queueConfig)->toBeNull();
        }
    }
});

it('records queued=true and a populated queue_config for ProcessOrder', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\ProcessOrder');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: 'redis',
        queue: 'high',
        delay: 30,
        tries: 5,
        timeout: 120,
        backoff: 10,
    ));
    expect($entry->file)->toBe('app/Jobs/ProcessOrder.php');
});

it('records queued=true and a partial queue_config for SendInvoice', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\SendInvoice');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: null,
        queue: 'invoices',
        delay: null,
        tries: 3,
        timeout: null,
        backoff: null,
    ));
});

it('records queued=false and queue_config=null for RunReport', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Jobs\\RunReport');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeFalse();
    expect($entry->queueConfig)->toBeNull();
});

it('discovers ChargeCustomer outside app/Jobs/ via dispatch-site seeding', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $entry = jobByFqcn($entries, 'App\\Domain\\Billing\\Jobs\\ChargeCustomer');

    expect($entry)->not->toBeNull();
    expect($entry->file)->toBe('app/Domain/Billing/Jobs/ChargeCustomer.php');
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: 'sqs',
        queue: 'billing',
        delay: null,
        tries: null,
        timeout: null,
        backoff: null,
    ));
});

it('sorts job entries by FQCN ascending', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    $fqcns = array_map(fn (JobEntry $e): string => $e->fqcn, $entries);
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    foreach ($entries as $entry) {
        expect($entry->file)->not->toContain('\\');
        expect($entry->file)->toStartWith('app/');
    }
});

it('does not seed event classes dispatched via the Dispatchable form into jobs[]', function () {
    $entries = (new JobsScanner)->scan(jobsFixturePath())['jobs'];

    // App\Events\CartCleared uses the Dispatchable trait so
    // `CartCleared::dispatch()` is syntactically valid. The DispatchSiteVisitor
    // emits it with provisionalKind: ambiguous, which JobsScanner used to seed
    // unconditionally — producing a spurious jobs[] entry for an event class.
    // The guard requires either app/Jobs/ location or ShouldQueue implements
    // for ambiguous-kind sites; CartCleared satisfies neither.
    expect(jobByFqcn($entries, 'App\\Events\\CartCleared'))->toBeNull();
});
