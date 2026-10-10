<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A PHP file the scan could not parse, with the parser's reason.
 *
 * @internal
 */
final readonly class SkippedFile
{
    public function __construct(
        public string $file,
        public ?int $line,
        public string $message,
    ) {}
}
