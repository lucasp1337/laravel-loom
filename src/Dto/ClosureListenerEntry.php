<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

final class ClosureListenerEntry
{
    public function __construct(
        public readonly string $event,
        public readonly string $file,
        public readonly int $line,
        public readonly int $endLine,
        public readonly ListenerRegistration $registration,
        public readonly bool $queued,
    ) {
    }
}
