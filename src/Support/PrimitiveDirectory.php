<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Convention directories walked inside every scan directory.
 *
 * @internal
 */
enum PrimitiveDirectory: string
{
    case EVENTS = 'Events';
    case LISTENERS = 'Listeners';
    case JOBS = 'Jobs';
    case MAIL = 'Mail';
    case NOTIFICATIONS = 'Notifications';
}
