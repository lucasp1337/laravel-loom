<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Internal scanner state: a discovered job + the file/line we located it at. */
final class JobLocation
{
    /**
     * @param  array<string, string|int|null>  $queueConfig
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly bool $queued,
        public readonly array $queueConfig,
    ) {
    }
}
