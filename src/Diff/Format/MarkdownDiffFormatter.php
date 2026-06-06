<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

use Lucasp\Loom\Diff\Result\ChangedEntry;
use Lucasp\Loom\Diff\Result\DiffResult;
use Lucasp\Loom\Diff\Result\SectionDiff;
use Lucasp\Loom\Diff\Result\SubListDelta;

/**
 * GitHub-flavored Markdown diff, suitable to paste into a PR comment: a `##`
 * heading per non-empty section with bullet lists for added/removed entries
 * and nested lists for changed entries' field and sublist deltas.
 */
final class MarkdownDiffFormatter implements DiffFormatter
{
    public function format(DiffResult $result): string
    {
        if (! $result->hasChanges()) {
            return 'No semantic changes.';
        }

        $lines = [];
        foreach ($result->sections() as $section => $diff) {
            if ($diff->isEmpty()) {
                continue;
            }
            $lines[] = '## '.$section;
            $lines[] = '';
            $this->appendSection($lines, $diff);
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines), "\n");
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendSection(array &$lines, SectionDiff $diff): void
    {
        foreach ($diff->added as $entry) {
            $lines[] = '- **added** `'.$this->encode($entry).'`';
        }
        foreach ($diff->removed as $entry) {
            $lines[] = '- **removed** `'.$this->encode($entry).'`';
        }
        foreach ($diff->changed as $entry) {
            $this->appendChanged($lines, $entry);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendChanged(array &$lines, ChangedEntry $entry): void
    {
        $lines[] = '- **changed** `'.$entry->identity.'`';
        foreach ($entry->fieldChanges as $change) {
            $lines[] = '  - `'.$change->field.'`: `'.$this->encode($change->old)
                .'` → `'.$this->encode($change->new).'`';
        }
        foreach ($entry->subListDeltas as $delta) {
            $this->appendDelta($lines, $delta);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendDelta(array &$lines, SubListDelta $delta): void
    {
        $lines[] = '  - `'.$delta->field.'`:';
        foreach ($delta->added as $member) {
            $lines[] = '    - `+` `'.$this->encode($member).'`';
        }
        foreach ($delta->removed as $member) {
            $lines[] = '    - `-` `'.$this->encode($member).'`';
        }
    }

    private function encode(mixed $value): string
    {
        if (is_array($value) && array_key_exists(SubListDelta::SCALAR_MEMBER_KEY, $value) && count($value) === 1) {
            $value = $value[SubListDelta::SCALAR_MEMBER_KEY];
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
