<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How an observer registration was discovered, emitted on
 * `observers[*].registration`: a `Model::observe(...)` call or an
 * `#[ObservedBy]` attribute.
 */
enum ObserverRegistration: string
{
    case OBSERVE_CALL = 'observe_call';
    case ATTRIBUTE = 'attribute';
}
