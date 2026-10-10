<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * The six queue-config properties Loom tracks per queueable class.
 *
 * @internal
 */
final readonly class QueueConfigData
{
    public function __construct(
        public string|int|null $connection,
        public string|int|null $queue,
        public string|int|null $delay,
        public string|int|null $tries,
        public string|int|null $timeout,
        public string|int|null $backoff,
    ) {}
}
