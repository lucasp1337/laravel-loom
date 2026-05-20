<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Internal scanner state: a discovered mailable enriched with file path + queued. */
final class MailableLocation
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly bool $queued,
        public readonly QueueConfigData $queueConfig,
    ) {
    }
}
