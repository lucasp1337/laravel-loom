<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Schema-shape DTO for an entry in the `jobs[]` section.
 *
 * @internal
 */
final readonly class JobEntry
{
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public bool $queued,
        public ?QueueConfigData $queueConfig,
    ) {}
}
