<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ScheduleKind;

/** @internal */
final readonly class ScheduledEntry
{
    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $constraints
     */
    public function __construct(
        public ScheduleKind $kind,
        public ?string $name,
        public ?string $target,
        public array $arguments,
        public ?string $queue,
        public ?string $connection,
        public ?string $cron,
        public ?ScheduleFrequency $frequency,
        public ?string $timezone,
        public bool $withoutOverlapping,
        public ?int $withoutOverlappingExpiresAt,
        public bool $onOneServer,
        public bool $runInBackground,
        public bool $evenInMaintenanceMode,
        public array $constraints,
        public string $file,
        public int $line,
    ) {}
}
