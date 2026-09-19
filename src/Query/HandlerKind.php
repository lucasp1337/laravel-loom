<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/**
 * What handles an event. Observers are absent on purpose: the index links
 * observers to model events, not to entries of `events[]`.
 */
enum HandlerKind: string
{
    case LISTENER = 'listener';
    case CLOSURE = 'closure';
}
