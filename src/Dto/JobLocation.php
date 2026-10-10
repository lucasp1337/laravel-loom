<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Internal scanner state: a discovered job + the file/line we located it at.
 *
 * @internal
 */
final readonly class JobLocation
{
    public function __construct(
        public string $file,
        public int $line,
        public bool $queued,
        public QueueConfigData $queueConfig,
    ) {}
}
