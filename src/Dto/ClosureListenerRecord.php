<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Scanner-level closure handler: visitor output enriched with the file path. */
final class ClosureListenerRecord
{
    public function __construct(
        public readonly string $event,
        public readonly string $file,
        public readonly int $line,
        public readonly string $registration,
    ) {
    }
}
