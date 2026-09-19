<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class ClosureHandler
{
    public function __construct(
        public string $file,
        public int $line,
        public bool $queued,
    ) {
    }

    /** @return array{file: string, line: int, queued: bool} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'queued' => $this->queued];
    }
}
