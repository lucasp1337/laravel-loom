<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/** @internal */
final readonly class ClosureListenerEntry
{
    public function __construct(
        public string $event,
        public string $file,
        public int $line,
        public int $endLine,
        public ListenerRegistration $registration,
        public bool $queued,
    ) {}
}
