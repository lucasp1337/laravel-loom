<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A queueable/dispatchable job: its queue configuration, the sites that
 * dispatch it, and what it dispatches in turn. Read model for the `jobs`
 * section.
 */
final readonly class Job
{
    /**
     * @param  list<DispatchSite>  $dispatchedFrom
     * @param  list<Dispatch>  $dispatches
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public bool $queued,
        public ?QueueConfig $queueConfig,
        public array $dispatchedFrom,
        public array $dispatches,
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
            dispatchedFrom: Hydrate::list($data, Field::DISPATCHED_FROM, DispatchSite::fromArray(...)),
            dispatches: Hydrate::list($data, Field::DISPATCHES, Dispatch::fromArray(...)),
        );
    }
}
