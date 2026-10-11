<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/**
 * Scanner-level closure handler: visitor output enriched with the file path.
 *
 * @internal
 */
final readonly class ClosureListenerRecord
{
    public function __construct(
        public string $event,
        public string $file,
        public int $line,
        public int $endLine,
        public ListenerRegistration $registration,
    ) {}
}
