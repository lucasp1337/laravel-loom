<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\ScheduleKind;

/**
 * A scheduled task (command, job, closure, or shell exec) with its cron
 * expression — or structured sub-minute `frequency` — and lifecycle modifiers.
 * Read model for the `scheduled` section.
 */
final readonly class Scheduled
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
        public ?Frequency $frequency,
        public ?string $timezone,
        public bool $withoutOverlapping,
        public ?int $withoutOverlappingExpiresAt,
        public bool $onOneServer,
        public bool $runInBackground,
        public bool $evenInMaintenanceMode,
        public array $constraints,
        public string $file,
        public int $line,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $frequency = Hydrate::nullableArray($data, Field::FREQUENCY);

        return new self(
            kind: ScheduleKind::from(Hydrate::string($data, Field::KIND)),
            name: Hydrate::nullableString($data, Field::NAME),
            target: Hydrate::nullableString($data, Field::TARGET),
            arguments: Hydrate::stringList($data, Field::ARGUMENTS),
            queue: Hydrate::nullableString($data, Field::QUEUE),
            connection: Hydrate::nullableString($data, Field::CONNECTION),
            cron: Hydrate::nullableString($data, Field::CRON),
            frequency: $frequency === null ? null : Frequency::fromArray($frequency),
            timezone: Hydrate::nullableString($data, Field::TIMEZONE),
            withoutOverlapping: Hydrate::bool($data, Field::WITHOUT_OVERLAPPING),
            withoutOverlappingExpiresAt: Hydrate::nullableInt($data, Field::WITHOUT_OVERLAPPING_EXPIRES_AT),
            onOneServer: Hydrate::bool($data, Field::ON_ONE_SERVER),
            runInBackground: Hydrate::bool($data, Field::RUN_IN_BACKGROUND),
            evenInMaintenanceMode: Hydrate::bool($data, Field::EVEN_IN_MAINTENANCE_MODE),
            constraints: Hydrate::stringList($data, Field::CONSTRAINTS),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
        );
    }
}
