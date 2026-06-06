<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * The diff of one section: entries added, removed, and changed.
 */
final class SectionDiff
{
    /**
     * @param  list<array<string,mixed>>  $added
     * @param  list<array<string,mixed>>  $removed
     * @param  list<ChangedEntry>  $changed
     */
    public function __construct(
        public readonly array $added,
        public readonly array $removed,
        public readonly array $changed,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }
}
