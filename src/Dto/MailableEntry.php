<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final class MailableEntry
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly int $line,
        public readonly bool $queued,
        public readonly ?QueueConfigData $queueConfig,
    ) {
    }
}
