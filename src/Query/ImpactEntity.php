<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/** @internal */
enum ImpactEntity: string
{
    case EVENT = 'event';
    case LISTENER = 'listener';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';
    case UNKNOWN = 'unknown';
}
