<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\MailableScanner;

function mailableFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/mailable-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @return array<string, mixed>|null
 */
function mailableByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Empty-path behaviour
// ---------------------------------------------------------------------------

it('returns an empty mailables array when neither app/Mail nor app/ exist', function () {
    $entries = (new MailableScanner)->scan(sys_get_temp_dir())['mailables'];

    expect($entries)->toBe([]);
});

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

it('discovers the expected set of mailables from the fixture app', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $fqcns = array_column($entries, 'fqcn');

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
    expect($entry['file'])->toBe('app/Domain/Billing/Mail/InvoiceMailable.php');
});

// ---------------------------------------------------------------------------
// Required top-level keys + scanner-default sent_from
// ---------------------------------------------------------------------------

it('emits each entry with all required top-level keys', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['fqcn', 'file', 'line', 'queued', 'queue_config', 'sent_from']);
        // Scanner emits sent_from empty; cross-link fills it.
        expect($entry['sent_from'])->toBe([]);
    }
});

// ---------------------------------------------------------------------------
// Queue detection + queue_config
// ---------------------------------------------------------------------------

it('records queued=true and a populated queue_config for OrderShipped', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\OrderShipped');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => 'redis',
        'queue' => 'mail',
        'delay' => null,
        'tries' => 3,
        'timeout' => 60,
        'backoff' => null,
    ]);
    expect($entry['file'])->toBe('app/Mail/OrderShipped.php');
});

it('records queued=false and queue_config=null for WelcomeEmail', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\WelcomeEmail');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeFalse();
    expect($entry['queue_config'])->toBeNull();
});

it('detects indirect ShouldQueue via a parent abstract mailable', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $entry = mailableByFqcn($entries, 'App\\Mail\\IndirectlyQueuedMail');

    expect($entry)->not->toBeNull();
    // IndirectlyQueuedMail does NOT implement ShouldQueue directly — it extends
    // AbstractQueuedMail (abstract, in app/) which does. Resolver-driven detection
    // should walk the extends chain and surface the indirect implementation.
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => null,
        'queue' => 'mail-indirect',
        'delay' => null,
        'tries' => null,
        'timeout' => null,
        'backoff' => null,
    ]);
});

// ---------------------------------------------------------------------------
// Sorting + path normalisation
// ---------------------------------------------------------------------------

it('sorts mailable entries by FQCN ascending', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    $fqcns = array_column($entries, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new MailableScanner)->scan(mailableFixturePath())['mailables'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', (string) $entry['file']);
        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        expect($file)->toStartWith('app/');
    }
});
