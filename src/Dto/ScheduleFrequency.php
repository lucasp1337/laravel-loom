<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\FrequencyUnit;

/**
 * A structured sub-minute schedule frequency
 * (e.g. everyFifteenSeconds → unit=seconds, every=15).
 */
final readonly class ScheduleFrequency
{
    public function __construct(
        public FrequencyUnit $unit,
        public int $every,
    ) {
    }
}
