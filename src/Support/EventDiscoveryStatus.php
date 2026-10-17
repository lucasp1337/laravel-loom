<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Whether the app runs Laravel's listener discovery.
 *
 * @internal
 */
enum EventDiscoveryStatus
{
    /** Discovery is on; classes under the discovery directories are listeners. */
    case ENABLED;

    /** Discovery is off; only explicit registrations make a listener. */
    case DISABLED;

    /** The switch or the directories could not be read statically. */
    case UNVERIFIED;
}
