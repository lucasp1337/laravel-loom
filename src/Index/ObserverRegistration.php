<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How an observer registration was discovered, emitted on
 * `observers[*].registration`.
 *
 * @api
 */
enum ObserverRegistration: string implements Summarized
{
    case ATTRIBUTE = 'attribute';
    case OBSERVERS_ARRAY = 'observers_array';
    case OBSERVE_CALL = 'observe_call';

    public function summary(): string
    {
        return match ($this) {
            self::OBSERVE_CALL => '`Model::observe(Observer::class)`, including `static::observe()` in `booted()`.',
            self::ATTRIBUTE => 'an `#[ObservedBy]` attribute on the model.',
            self::OBSERVERS_ARRAY => 'the `$observers` property of an EventServiceProvider.',
        };
    }
}
