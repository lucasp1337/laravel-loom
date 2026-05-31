<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

final class ListenerEntry
{
    /**
     * @param  list<ListenerHandle>  $handles
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly int $line,
        public readonly array $handles,
        public readonly ListenerRegistration $registration,
        public readonly bool $queued,
    ) {
    }
}
