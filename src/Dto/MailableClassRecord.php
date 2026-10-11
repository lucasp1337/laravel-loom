<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Visitor output for a discovered mailable class (queued resolved by the scanner).
 *
 * @internal
 */
final readonly class MailableClassRecord
{
    public function __construct(
        public string $fqcn,
        public int $line,
        public QueueConfigData $queueConfig,
    ) {}
}
