<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final class JobClassRecord
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly QueueConfigData $queueConfig,
    ) {}
}
