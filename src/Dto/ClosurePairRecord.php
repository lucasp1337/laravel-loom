<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/**
 * Visitor-level closure handler: an (event, line, endLine, registration) tuple.
 *
 * @internal
 */
final readonly class ClosurePairRecord
{
    public function __construct(
        public string $event,
        public int $line,
        public int $endLine,
        public ListenerRegistration $registration,
    ) {}
}
