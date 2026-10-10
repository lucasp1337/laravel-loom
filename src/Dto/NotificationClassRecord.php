<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Visitor output for a notification class. `queued` is resolved by the scanner.
 *
 * @internal
 */
final readonly class NotificationClassRecord
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $fqcn,
        public int $line,
        public QueueConfigData $queueConfig,
        public array $channels,
        public bool $channelsDynamic,
    ) {}
}
