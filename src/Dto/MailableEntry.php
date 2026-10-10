<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class MailableEntry
{
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public bool $queued,
        public ?QueueConfigData $queueConfig,
    ) {}
}
