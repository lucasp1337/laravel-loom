<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Visitor output for a discovered mailable class (queued resolved by the scanner). */
final class MailableClassRecord
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly QueueConfigData $queueConfig,
    ) {
    }
}
