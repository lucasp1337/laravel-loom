<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A mailable class: its queue configuration and the sites that send it. Read
 * model for the `mailables` section.
 */
final readonly class Mailable
{
    /** @param  list<DispatchSite>  $sentFrom */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public bool $queued,
        public ?QueueConfig $queueConfig,
        public array $sentFrom,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $queueConfig = Hydrate::nullableArray($data, Field::QUEUE_CONFIG);

        return new self(
            fqcn: Hydrate::string($data, Field::FQCN),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            queued: Hydrate::bool($data, Field::QUEUED),
            queueConfig: $queueConfig === null ? null : QueueConfig::fromArray($queueConfig),
            sentFrom: Hydrate::list($data, Field::SENT_FROM, DispatchSite::fromArray(...)),
        );
    }
}
