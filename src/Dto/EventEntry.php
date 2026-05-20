<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class EventEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $fqcn,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
