<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Internal scanner state: a notification enriched with file path + queued. */
final class NotificationLocation
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly bool $queued,
        public readonly QueueConfigData $queueConfig,
        public readonly array $channels,
        public readonly bool $channelsDynamic,
    ) {
    }
}
