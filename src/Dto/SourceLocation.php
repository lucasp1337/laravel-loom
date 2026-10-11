<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A `(file, line)` pair — used wherever the scanner caches a class's location.
 *
 * @internal
 */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public int $line,
    ) {}
}
