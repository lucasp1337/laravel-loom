<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class NotificationClassRecord
{
    /**
     * @param  array<string, string|int|null>  $queueConfig
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly bool $queued,
        public readonly array $queueConfig,
        public readonly array $channels,
        public readonly bool $channelsDynamic,
    ) {}
}
