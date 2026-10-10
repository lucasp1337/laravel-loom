<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Internal scanner state: a discovered mailable enriched with file path + queued.
 *
 * @internal
 */
final readonly class MailableLocation
{
    public function __construct(
        public string $file,
        public int $line,
        public bool $queued,
        public QueueConfigData $queueConfig,
    ) {}
}
