<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\NotificationEntry;
use Lucasp\Loom\Dto\QueueConfigData;
use Lucasp\Loom\Scanners\NotificationScanner;

function notificationFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/notification-fixture-app';
}

/**
 * @param  list<NotificationEntry>  $entries
 */
function notificationByFqcn(array $entries, string $fqcn): ?NotificationEntry
{
    foreach ($entries as $entry) {
        if ($entry->fqcn === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('returns an empty notifications array when neither app/Notifications nor app/ exist', function () {
    $entries = (new NotificationScanner)->scan(sys_get_temp_dir())['notifications'];

    expect($entries)->toBe([]);
});

it('discovers the expected set of notifications from the fixture app', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $fqcns = array_map(fn (NotificationEntry $e): string => $e->fqcn, $entries);

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
    expect($entry->file)->toBe('app/Domain/Accounts/Notifications/InvitedNotification.php');
});

it('emits each entry as a NotificationEntry DTO carrying every schema field', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(NotificationEntry::class);
        expect($entry->fqcn)->toBeString();
        expect($entry->file)->toBeString();
        expect($entry->line)->toBeInt();
        expect($entry->queued)->toBeBool();
        if ($entry->queued) {
            expect($entry->queueConfig)->toBeInstanceOf(QueueConfigData::class);
        } else {
            expect($entry->queueConfig)->toBeNull();
        }
        expect($entry->channels)->toBeArray();
        expect($entry->channelsDynamic)->toBeBool();
    }
});

it('records queued=true and a populated queue_config for InvoicePaid', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\InvoicePaid');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeTrue();
    expect($entry->queueConfig)->toEqual(new QueueConfigData(
        connection: 'redis',
        queue: 'notifications',
        delay: null,
        tries: 5,
        timeout: 90,
        backoff: null,
    ));
    expect($entry->file)->toBe('app/Notifications/InvoicePaid.php');
});

it('records queued=false and queue_config=null for PasswordReset', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\PasswordReset');

    expect($entry)->not->toBeNull();
    expect($entry->queued)->toBeFalse();
    expect($entry->queueConfig)->toBeNull();
});

it('extracts string + FQCN class-constant channels for InvoicePaid', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\InvoicePaid');

    expect($entry)->not->toBeNull();
    expect($entry->channelsDynamic)->toBeFalse();
    expect($entry->channels)->toBe([
        'mail',
        'database',
        'App\\Channels\\SlackChannel',
    ]);
});

it('extracts the single mail channel for PasswordReset', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\PasswordReset');

    expect($entry)->not->toBeNull();
    expect($entry->channels)->toBe(['mail']);
    expect($entry->channelsDynamic)->toBeFalse();
});

it('flags channels_dynamic=true and channels=[] for DynamicChannelNotification', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\DynamicChannelNotification');

    expect($entry)->not->toBeNull();
    expect($entry->channels)->toBe([]);
    expect($entry->channelsDynamic)->toBeTrue();
});

it('emits channels=[] and channels_dynamic=false when no via() method is declared', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $entry = notificationByFqcn($entries, 'App\\Notifications\\NoViaNotification');

    expect($entry)->not->toBeNull();
    expect($entry->channels)->toBe([]);
    expect($entry->channelsDynamic)->toBeFalse();
});

it('sorts notification entries by FQCN ascending', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    $fqcns = array_map(fn (NotificationEntry $e): string => $e->fqcn, $entries);
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = (new NotificationScanner)->scan(notificationFixturePath())['notifications'];

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);
        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        expect($file)->toStartWith('app/');
    }
});
