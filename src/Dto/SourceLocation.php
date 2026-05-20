<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A `(file, line)` pair — used wherever the scanner caches a class's location. */
final class SourceLocation
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
