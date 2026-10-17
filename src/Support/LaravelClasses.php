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
    case NOTIFICATION = 'Illuminate\\Notifications\\Notification';
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
}
