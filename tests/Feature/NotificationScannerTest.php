<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\NotificationScanner;

function notificationFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/notification-fixture-app';
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @return array<string, mixed>|null
 */
function notificationByFqcn(array $entries, string $fqcn): ?array
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

it('returns an empty notifications array when neither app/Notifications nor app/ exist', function () {
    $entries = (new NotificationScanner)->scan(sys_get_temp_dir())['notifications'];

    expect($entries)->toBe([]);
});

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

it('discovers the expected set of notifications from the fixture app', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $fqcns = array_column($entries, 'fqcn');

    expect($fqcns)->toContain('App\\Notifications\\InvoicePaid');
    expect($fqcns)->toContain('App\\Notifications\\PasswordReset');
    expect($fqcns)->toContain('App\\Notifications\\DynamicChannelNotification');
    expect($fqcns)->toContain('App\\Notifications\\NoViaNotification');
    expect($fqcns)->toContain('App\\Domain\\Accounts\\Notifications\\InvitedNotification');
});

it('skips abstract notification classes', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    expect(notificationByFqcn($entries, 'App\\Notifications\\AbstractNotification'))->toBeNull();
});

it('discovers InvitedNotification outside app/Notifications/ via dispatch-site seeding', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Domain\\Accounts\\Notifications\\InvitedNotification');

    expect($entry)->not->toBeNull();
    expect($entry['file'])->toBe('app/Domain/Accounts/Notifications/InvitedNotification.php');
});

// ---------------------------------------------------------------------------
// Required top-level keys + scanner-default notified_from
// ---------------------------------------------------------------------------

it('emits each entry with all required top-level keys', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys([
            'fqcn',
            'file',
            'line',
            'queued',
            'queue_config',
            'channels',
            'channels_dynamic',
            'notified_from',
        ]);
        // Scanner emits notified_from empty; cross-link fills it.
        expect($entry['notified_from'])->toBe([]);
    }
});

// ---------------------------------------------------------------------------
// Queue detection
// ---------------------------------------------------------------------------

it('records queued=true and a populated queue_config for InvoicePaid', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\InvoicePaid');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeTrue();
    expect($entry['queue_config'])->toBe([
        'connection' => 'redis',
        'queue' => 'notifications',
        'delay' => null,
        'tries' => 5,
        'timeout' => 90,
        'backoff' => null,
    ]);
    expect($entry['file'])->toBe('app/Notifications/InvoicePaid.php');
});

it('records queued=false and queue_config=null for PasswordReset', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\PasswordReset');

    expect($entry)->not->toBeNull();
    expect($entry['queued'])->toBeFalse();
    expect($entry['queue_config'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Channel extraction
// ---------------------------------------------------------------------------

it('extracts string + FQCN class-constant channels for InvoicePaid', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\InvoicePaid');

    expect($entry)->not->toBeNull();
    expect($entry['channels_dynamic'])->toBeFalse();
    // Order matches the source-code order of the literal array; the per-doc
    // contract: bare strings lowercased, class-constants emitted as FQCN.
    expect($entry['channels'])->toBe([
        'mail',
        'database',
        'App\\Channels\\SlackChannel',
    ]);
});

it('extracts the single mail channel for PasswordReset', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\PasswordReset');

    expect($entry)->not->toBeNull();
    expect($entry['channels'])->toBe(['mail']);
    expect($entry['channels_dynamic'])->toBeFalse();
});

it('flags channels_dynamic=true and channels=[] for DynamicChannelNotification', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\DynamicChannelNotification');

    expect($entry)->not->toBeNull();
    expect($entry['channels'])->toBe([]);
    expect($entry['channels_dynamic'])->toBeTrue();
});

it('emits channels=[] and channels_dynamic=false when no via() method is declared', function () {
    // Absence of via() means "no channels declared" — an intentional zero,
    // not unknown. channels_dynamic is true only when via() exists but its
    // body isn't statically resolvable. See docs/scanners/notifications.md.
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\NoViaNotification');

    expect($entry)->not->toBeNull();
    expect($entry['channels'])->toBe([]);
    expect($entry['channels_dynamic'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Sorting + path normalisation
// ---------------------------------------------------------------------------

it('sorts notification entries by FQCN ascending', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $fqcns = array_column($entries, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', (string) $entry['file']);
        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        expect($file)->toStartWith('app/');
    }
});
