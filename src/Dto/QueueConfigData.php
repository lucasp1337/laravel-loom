<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** The six queue-config properties Loom tracks per queueable class. */
final class QueueConfigData
{
    public function __construct(
        public readonly string|int|null $connection,
        public readonly string|int|null $queue,
        public readonly string|int|null $delay,
        public readonly string|int|null $tries,
        public readonly string|int|null $timeout,
        public readonly string|int|null $backoff,
    ) {
    }
}
