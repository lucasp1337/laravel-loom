<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * The full semantic diff between two indexes, keyed by section name in
 * registry order.
 */
final class DiffResult
{
    /**
     * @param  array<string,SectionDiff>  $sections
     */
    public function __construct(
        public readonly array $sections,
    ) {
    }

    public function hasChanges(): bool
    {
        foreach ($this->sections as $section) {
            if (! $section->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,SectionDiff>
     */
    public function sections(): array
    {
        return $this->sections;
    }
}
