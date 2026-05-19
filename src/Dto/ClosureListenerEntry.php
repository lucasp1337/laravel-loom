<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class ClosureListenerEntry
{
    public function __construct(
        public readonly string $event,
        public readonly string $file,
        public readonly int $line,
        public readonly string $registration,
        public readonly bool $queued,
    ) {
    }
}
