<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The unit of a sub-minute scheduling frequency, emitted on
 * `scheduled[*].frequency.unit`. Sub-minute schedules can't be expressed
 * as a 5-field cron, so they carry a structured frequency instead.
 */
enum FrequencyUnit: string
{
    case SECONDS = 'seconds';
}
