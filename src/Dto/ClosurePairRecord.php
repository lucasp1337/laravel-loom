<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** Visitor-level closure handler: an (event, line, registration) tuple. */
final class ClosurePairRecord
{
    public function __construct(
        public readonly string $event,
        public readonly int $line,
        public readonly string $registration,
    ) {
    }
}
