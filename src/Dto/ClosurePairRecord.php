<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/** Visitor-level closure handler: an (event, line, endLine, registration) tuple. */
final class ClosurePairRecord
{
    public function __construct(
        public readonly string $event,
        public readonly int $line,
        public readonly int $endLine,
        public readonly ListenerRegistration $registration,
    ) {
    }
}
