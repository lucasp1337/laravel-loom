<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How a listener registration was discovered, emitted on
 * `listeners[*].registration` and `closure_listeners[*].registration`.
 *
 * `AUTO_DISCOVERED` applies only to class listeners (Laravel's convention-based
 * discovery); closure listeners never carry it.
 */
enum ListenerRegistration: string
{
    case LISTEN_ARRAY = 'listen_array';
    case AUTO_DISCOVERED = 'auto_discovered';
    case EVENT_LISTEN_CALL = 'event_listen_call';
    case SUBSCRIBER = 'subscriber';
}
