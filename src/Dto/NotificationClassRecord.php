<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Visitor output for a notification class. `queued` is resolved by the scanner. */
final class NotificationClassRecord
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly QueueConfigData $queueConfig,
        public readonly array $channels,
        public readonly bool $channelsDynamic,
    ) {
    }
}
