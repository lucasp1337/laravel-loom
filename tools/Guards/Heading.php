<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

/** A markdown heading: its level (1 for `#`) and text. */
final readonly class Heading
{
    public function __construct(
        public int $level,
        public string $text,
    ) {}
}
