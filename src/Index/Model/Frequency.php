<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\FrequencyUnit;

/**
 * A sub-minute scheduling frequency (e.g. `everyTenSeconds`), emitted on
 * `scheduled[*].frequency` when a task runs more often than once a minute and
 * therefore cannot be expressed as a 5-field cron (`cron` stays null).
 */
final readonly class Frequency
{
    public function __construct(
        public FrequencyUnit $unit,
        public int $every,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            unit: FrequencyUnit::from(Hydrate::string($data, Field::UNIT)),
            every: Hydrate::int($data, Field::EVERY),
        );
    }
}
