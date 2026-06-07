<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A notification class: its queue configuration, the channels it sends on,
 * and the sites that notify it. Read model for the `notifications` section.
 */
final readonly class Notification
{
    /**
     * @param  list<DispatchSite>  $notifiedFrom
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public bool $queued,
        public ?QueueConfig $queueConfig,
        public array $notifiedFrom,
        public array $channels,
        public bool $channelsDynamic,
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
            notifiedFrom: Hydrate::list($data, Field::NOTIFIED_FROM, DispatchSite::fromArray(...)),
            channels: Hydrate::stringList($data, Field::CHANNELS),
            channelsDynamic: Hydrate::bool($data, Field::CHANNELS_DYNAMIC),
        );
    }
}
