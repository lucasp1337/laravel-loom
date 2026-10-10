<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class EventEntry
{
    public function __construct(
        public string $id,
        public string $fqcn,
        public string $file,
        public int $line,
    ) {}
}
