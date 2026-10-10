<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * The diff of one section: entries added, removed, and changed.
 *
 * @internal
 */
final readonly class SectionDiff
{
    /**
     * @param  list<array<string,mixed>>  $added
     * @param  list<array<string,mixed>>  $removed
     * @param  list<ChangedEntry>  $changed
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }
}
