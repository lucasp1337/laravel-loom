<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How a listener registration was discovered, emitted on
 * `listeners[*].registration` and `closure_listeners[*].registration`.
 *
 * @api
 */
enum ListenerRegistration: string implements Summarized
{
    case LISTEN_ARRAY = 'listen_array';
    case AUTO_DISCOVERED = 'auto_discovered';
    case EVENT_LISTEN_CALL = 'event_listen_call';
    case SUBSCRIBER = 'subscriber';
    case DISCOVERY_UNVERIFIED = 'discovery_unverified';
    case MODEL_HOOK = 'model_hook';

    public function summary(): string
    {
        return match ($this) {
            self::SUBSCRIBER => 'inside a subscriber\'s `subscribe()`, as a return-array value or an `$events->listen()` call.',
            self::LISTEN_ARRAY => 'an entry of `$listen`.',
            self::EVENT_LISTEN_CALL => 'an `Event::listen()` call.',
            self::AUTO_DISCOVERED => 'a typed `handle()` under a discovery directory, with event discovery verified on; class listeners only.',
            self::DISCOVERY_UNVERIFIED => 'the same, but whether the app runs event discovery could not be read; class listeners only.',
            self::MODEL_HOOK => 'a closure passed to a model\'s static hook registrar such as `static::creating(fn ...)`; closure listeners only.',
        };
    }
}
