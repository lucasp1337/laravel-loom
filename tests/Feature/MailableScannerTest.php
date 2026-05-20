<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\MailableEntry;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Scanners\MailableScanner;

function mailableFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/mailable-fixture-app';
}

/**
 * @param  list<MailableEntry>  $entries
 */
function mailableByFqcn(array $entries, string $fqcn): ?MailableEntry
{
    foreach ($entries as $entry) {
        if ($entry->fqcn === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('returns an empty mailables array when neither app/Mail nor app/ exist', function () {
    $entries = (new MailableScanner)->scan(sys_get_temp_dir())['mailables'];

    expect($entries)->toBe([]);
});

it('discovers the expected set of mailables from the fixture app', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $fqcns = array_map(fn (MailableEntry $e): string => $e->fqcn, $entries);

    expect($fqcns)->toContain('App\\Mail\\OrderShipped');
    expect($fqcns)->toContain('App\\Mail\\WelcomeEmail');
    expect($fqcns)->toContain('App\\Mail\\IndirectlyQueuedMail');
    expect($fqcns)->toContain('App\\Domain\\Billing\\Mail\\InvoiceMailable');
});

it('skips abstract mailable classes', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    expect(mailableByFqcn($entries, 'App\\Mail\\AbstractMailable'))->toBeNull();
    expect(mailableByFqcn($entries, 'App\\Mail\\AbstractQueuedMail'))->toBeNull();
});

it('discovers InvoiceMailable outside app/Mail/ via dispatch-site seeding', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Domain\\Billing\\Mail\\InvoiceMailable');

    expect($entry)->not->toBeNull();
    expect($entry->file)->toBe('app/Domain/Billing/Mail/InvoiceMailable.php');
});

it('emits each entry as a MailableEntry DTO carrying every schema field', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(MailableEntry::class);
        expect($entry->fqcn)->toBeString();
        expect($entry->file)->toBeString();
        expect($entry->line)->toBeInt();
        expect($entry->queued)->toBeBool();
        if ($entry->queued) {
            expect($entry->queueConfig)->toBeInstanceOf(QueueConfigData::class);
        } else {
            expect($entry->queueConfig)->toBeNull();
        }
    }
});

it('records queued=true and a populated queue_config for OrderShipped', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\OrderShipped');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: 'redis',
        queue: 'mail',
        delay: null,
        tries: 3,
        timeout: 60,
        backoff: null,
    ));
    expect($entry->file)->toBe('app/Mail/OrderShipped.php');
});

it('records queued=false and queue_config=null for WelcomeEmail', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\WelcomeEmail');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeFalse();
    expect($entry->queueConfig)->toBeNull();
});

it('detects indirect ShouldQueue via a parent abstract mailable', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\IndirectlyQueuedMail');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: null,
        queue: 'mail-indirect',
        delay: null,
        tries: null,
        timeout: null,
        backoff: null,
    ));
});

it('sorts mailable entries by FQCN ascending', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $fqcns = array_map(fn (MailableEntry $e): string => $e->fqcn, $entries);
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);
        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        expect($file)->toStartWith('app/');
    }
});
