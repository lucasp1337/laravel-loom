<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ScheduleKind;

final class ScheduledEntry
{
    /**
     * @param  list<string>  $constraints
     */
    public function __construct(
        public readonly ScheduleKind $kind,
        public readonly ?string $target,
        public readonly ?string $cron,
        public readonly ?string $timezone,
        public readonly bool $withoutOverlapping,
        public readonly bool $onOneServer,
        public readonly bool $runInBackground,
        public readonly array $constraints,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
