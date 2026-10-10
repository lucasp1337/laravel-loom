<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class JobClassRecord
{
    public function __construct(
        public string $fqcn,
        public int $line,
        public QueueConfigData $queueConfig,
    ) {}
}
