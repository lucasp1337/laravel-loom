<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Non-facade Laravel FQCNs Loom matches against — contracts and
 * framework base classes.
 *
 * @internal
 */
enum LaravelClasses: string
{
    case SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';
    case SHOULD_QUEUE_AFTER_COMMIT = 'Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit';
    case SHOULD_HANDLE_EVENTS_AFTER_COMMIT = 'Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit';
    case NOTIFICATION = 'Illuminate\\Notifications\\Notification';
    case RESET_PASSWORD_NOTIFICATION = 'Illuminate\\Auth\\Notifications\\ResetPassword';
    case VERIFY_EMAIL_NOTIFICATION = 'Illuminate\\Auth\\Notifications\\VerifyEmail';
    case MAILABLE = 'Illuminate\\Mail\\Mailable';
    case EVENT_SERVICE_PROVIDER = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /**
     * `ShouldQueue` and the framework interfaces that extend it; a class
     * implementing any of them is queued.
     *
     * @return list<string>
     */
    public static function queueInterfaces(): array
    {
        return [self::SHOULD_QUEUE->value, self::SHOULD_QUEUE_AFTER_COMMIT->value];
    }

    /**
     * `Notification` and the framework notifications that extend it; a class
     * extending any of them is a notification.
     *
     * @return list<string>
     */
    public static function notificationClasses(): array
    {
        return [self::NOTIFICATION->value, self::RESET_PASSWORD_NOTIFICATION->value, self::VERIFY_EMAIL_NOTIFICATION->value];
    }
}
