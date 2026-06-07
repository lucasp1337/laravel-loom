<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * Fluent modifiers applied at a dispatch site (e.g. `->onQueue('high')`,
 * `->delay(60)`, `->afterCommit()`). Every field is null when not set.
 */
final readonly class DispatchOverrides
{
    public function __construct(
        public ?string $locale,
        public ?string $mailer,
        public ?string $connection,
        public ?string $queue,
        public ?int $delay,
        public ?bool $afterCommit,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $afterCommit = $data[Field::AFTER_COMMIT->value] ?? null;

        return new self(
            locale: Hydrate::nullableString($data, Field::LOCALE),
            mailer: Hydrate::nullableString($data, Field::MAILER),
            connection: Hydrate::nullableString($data, Field::CONNECTION),
            queue: Hydrate::nullableString($data, Field::QUEUE),
            delay: Hydrate::nullableInt($data, Field::DELAY),
            afterCommit: is_bool($afterCommit) ? $afterCommit : null,
        );
    }
}
