<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class JobClassRecord
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly bool $queued,
        public readonly bool $hasHandle,
        public readonly QueueConfigData $queueConfig,
    ) {
    }
}
