<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How a listener registration was discovered, emitted on
 * `listeners[*].registration` and `closure_listeners[*].registration`.
 *
 * `AUTO_DISCOVERED` applies only to class listeners, and only when event
 * discovery is verified on for the app. `DISCOVERY_UNVERIFIED` marks a class
 * under the discovery directory whose discovery switch could not be read.
 * `MODEL_HOOK` applies only to closure listeners: a `static::creating(fn ...)`
 * style Eloquent hook. Closure listeners never carry the discovery values.
 *
 * @api
 */
enum ListenerRegistration: string
{
    case LISTEN_ARRAY = 'listen_array';
    case AUTO_DISCOVERED = 'auto_discovered';
    case EVENT_LISTEN_CALL = 'event_listen_call';
    case SUBSCRIBER = 'subscriber';
    case DISCOVERY_UNVERIFIED = 'discovery_unverified';
    case MODEL_HOOK = 'model_hook';
}
