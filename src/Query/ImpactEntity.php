<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

enum ImpactEntity: string
{
    case EVENT = 'event';
    case LISTENER = 'listener';
    case JOB = 'job';
    case UNKNOWN = 'unknown';
}
