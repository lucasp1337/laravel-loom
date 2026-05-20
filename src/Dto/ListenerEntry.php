<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

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
        public readonly string $registration,
        public readonly bool $queued,
    ) {
    }
}
