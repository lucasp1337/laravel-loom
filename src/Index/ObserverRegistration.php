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
    case OBSERVE_CALL = 'observe_call';
    case ATTRIBUTE = 'attribute';

    public function summary(): string
    {
        return match ($this) {
            self::OBSERVE_CALL => '`Model::observe(Observer::class)`, including `static::observe()` in `booted()`.',
            self::ATTRIBUTE => 'an `#[ObservedBy]` attribute on the model.',
        };
    }
}
