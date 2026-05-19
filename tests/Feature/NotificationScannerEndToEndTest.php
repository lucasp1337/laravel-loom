<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\NotificationScanner;

function notificationEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/notification-fixture-app';
}

/**
 * @return array<string, mixed>
 */
function buildNotificationEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new NotificationScanner);
    $builder->register(new DispatchScanner);

    return $builder->build(notificationEndToEndFixturePath(), '12.x')->toArray();
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 * @return array<string, mixed>|null
 */
function notificationEntryByFqcn(array $entries, string $fqcn): ?array
{
    foreach ($entries as $entry) {
        if (($entry['fqcn'] ?? null) === $fqcn) {
            return $entry;
        }
    }

    return null;
}

it('produces a schema-valid index with NotificationScanner + DispatchScanner registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new NotificationScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(notificationEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('reports stats.notifications equal to the count of notification entries', function () {
    $payload = buildNotificationEndToEndPayload();

    expect($payload)->toHaveKey('notifications');
    expect($payload['stats'])->toHaveKey('notifications');
    expect($payload['stats']['notifications'])->toBe(count($payload['notifications']));
    expect($payload['stats']['notifications'])->toBeGreaterThan(0);
});

it('includes the known notification FQCNs in the built index', function () {
    $payload = buildNotificationEndToEndPayload();

    $fqcns = array_column($payload['notifications'], 'fqcn');

    expect($fqcns)->toContain('App\\Notifications\\InvoicePaid');
    expect($fqcns)->toContain('App\\Notifications\\PasswordReset');
    expect($fqcns)->toContain('App\\Notifications\\DynamicChannelNotification');
    expect($fqcns)->toContain('App\\Notifications\\NoViaNotification');
    expect($fqcns)->toContain('App\\Domain\\Accounts\\Notifications\\InvitedNotification');
});

it('populates notifications[InvoicePaid].notified_from for $user->notify(...) and Notification::send(...)', function () {
    $payload = buildNotificationEndToEndPayload();

    $entry = notificationEntryByFqcn($payload['notifications'], 'App\\Notifications\\InvoicePaid');
    expect($entry)->not->toBeNull();
    expect($entry['notified_from'])->not->toBe([]);

    $methods = array_column($entry['notified_from'], 'method');
    foreach ($methods as $method) {
        expect($method)->toBe('App\\Services\\Billing::chargeAndNotify');
    }

    $lines = array_column($entry['notified_from'], 'line');
    sort($lines);

    expect($lines)->toContain(19); // $user->notify(new InvoicePaid())
    expect($lines)->toContain(23); // Notification::send($users, new InvoicePaid())
    expect($lines)->toContain(40); // $user->notify(InvoicePaid::class) — class-constant form
});

it('populates notifications[PasswordReset].notified_from for notifyNow + Notification::sendNow', function () {
    $payload = buildNotificationEndToEndPayload();

    $entry = notificationEntryByFqcn($payload['notifications'], 'App\\Notifications\\PasswordReset');
    expect($entry)->not->toBeNull();
    expect($entry['notified_from'])->not->toBe([]);

    $lines = array_column($entry['notified_from'], 'line');
    sort($lines);

    expect($lines)->toContain(21); // $user->notifyNow(new PasswordReset())
    expect($lines)->toContain(25); // Notification::sendNow($users, new PasswordReset())
    expect($lines)->toContain(42); // Notification::send($users, PasswordReset::class) — class-constant form
});

it('populates notifications[InvitedNotification].notified_from for the Notification::route(...)->notify(...) chain', function () {
    $payload = buildNotificationEndToEndPayload();

    $entry = notificationEntryByFqcn($payload['notifications'], 'App\\Domain\\Accounts\\Notifications\\InvitedNotification');
    expect($entry)->not->toBeNull();
    expect($entry['notified_from'])->not->toBe([]);

    $methods = array_column($entry['notified_from'], 'method');
    expect($methods)->toContain('App\\Services\\Billing::chargeAndNotify');

    $lines = array_column($entry['notified_from'], 'line');
    expect($lines)->toContain(27);
});

it('handles arbitrary-depth Notification::route(...)->route(...)->notify(...) chains', function () {
    // Locks the contract that the chain walker descends through every
    // ->route(...) MethodCall link down to the Notification::route static
    // call. The fixture has a two-route chain at line 33; expect a
    // notified_from entry pointing at that line.
    $payload = buildNotificationEndToEndPayload();

    $entry = notificationEntryByFqcn($payload['notifications'], 'App\\Domain\\Accounts\\Notifications\\InvitedNotification');
    expect($entry)->not->toBeNull();

    $lines = array_column($entry['notified_from'], 'line');
    expect($lines)->toContain(33);
});

it('emits an unresolved_dispatches entry for $user->notify($dynamic) and no notifications row for it', function () {
    $payload = buildNotificationEndToEndPayload();

    $fqcns = array_column($payload['notifications'], 'fqcn');
    expect($fqcns)->not->toContain('dynamic');

    $unresolved = $payload['unresolved_dispatches'];
    expect($unresolved)->not->toBe([]);

    $files = array_column($unresolved, 'file');
    $forwardSlashed = array_map(
        static fn (string $f): string => str_replace(DIRECTORY_SEPARATOR, '/', $f),
        $files,
    );
    expect($forwardSlashed)->toContain('app/Services/Billing.php');
});

it('sorts notifications by fqcn ascending in the built index', function () {
    $payload = buildNotificationEndToEndPayload();

    /** @var array<int, array<string, mixed>> $notifications */
    $notifications = $payload['notifications'];

    $fqcns = array_column($notifications, 'fqcn');
    $sorted = $fqcns;
    sort($sorted, SORT_STRING);

    expect($fqcns)->toBe($sorted);
});
