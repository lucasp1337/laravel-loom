<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff;

use Lucasp\Loom\Diff\Result\DiffResult;

/**
 * Diffs two decoded Loom indexes section by section, in registry order.
 * Pure and framework-free: takes two associative arrays, returns a
 * {@see DiffResult}. Metadata blocks (loom_version, scanned_at, stats, …) are
 * ignored — only the sections in {@see DiffSpecRegistry} are compared.
 */
final class IndexDiffer
{
    private SectionComparator $comparator;

    public function __construct(?SectionComparator $comparator = null)
    {
        $this->comparator = $comparator ?? new SectionComparator;
    }

    /**
     * @param  array<string,mixed>  $old
     * @param  array<string,mixed>  $new
     */
    public function diff(array $old, array $new): DiffResult
    {
        $sections = [];
        foreach (DiffSpecRegistry::specs() as $spec) {
            $sections[$spec->section] = $this->comparator->compare(
                $spec,
                $this->section($old, $spec->section),
                $this->section($new, $spec->section),
            );
        }

        return new DiffResult($sections);
    }

    /**
     * Pull a section as a list of entry arrays, discarding anything malformed.
     *
     * @param  array<string,mixed>  $index
     * @return list<array<string,mixed>>
     */
    private function section(array $index, string $section): array
    {
        $raw = $index[$section] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                /** @var array<string,mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
