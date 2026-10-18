<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

/** One outcome stated in a trailing comment, anchored to its file and line. */
final readonly class Marker
{
    /** What `✗` states for a line that leaves no trace in the index. */
    public const NOT_RECORDED = 'not recorded';

    public function __construct(
        public MarkerKind $kind,
        public string $text,
        public string $file,
        public int $line,
    ) {}

    public function __toString(): string
    {
        return "{$this->file}:{$this->line} {$this->kind->value} {$this->text}";
    }
}
