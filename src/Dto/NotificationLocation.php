<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Internal scanner state: a notification enriched with file path + queued.
 *
 * @internal
 */
final readonly class NotificationLocation
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $file,
        public int $line,
        public bool $queued,
        public QueueConfigData $queueConfig,
        public array $channels,
        public bool $channelsDynamic,
    ) {}
}
