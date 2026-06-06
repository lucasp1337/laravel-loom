<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

use Lucasp\Loom\Diff\Result\ChangedEntry;
use Lucasp\Loom\Diff\Result\DiffResult;
use Lucasp\Loom\Diff\Result\FieldChange;
use Lucasp\Loom\Diff\Result\SectionDiff;
use Lucasp\Loom\Diff\Result\SubListDelta;

/**
 * Human-readable terminal diff with ANSI colors: green `+` for additions,
 * red `-` for removals, yellow `~` for changed entries.
 */
final class TextDiffFormatter implements DiffFormatter
{
    private const GREEN = "\033[32m";

    private const RED = "\033[31m";

    private const YELLOW = "\033[33m";

    private const BOLD = "\033[1m";

    private const RESET = "\033[0m";

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
            $lines[] = self::BOLD.$section.self::RESET;
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
            $lines[] = self::GREEN.'  + '.$this->encode($entry).self::RESET;
        }
        foreach ($diff->removed as $entry) {
            $lines[] = self::RED.'  - '.$this->encode($entry).self::RESET;
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
        $lines[] = self::YELLOW.'  ~ '.$entry->identity.self::RESET;
        foreach ($entry->fieldChanges as $change) {
            $lines[] = $this->fieldLine($change);
        }
        foreach ($entry->subListDeltas as $delta) {
            $this->appendDelta($lines, $delta);
        }
    }

    private function fieldLine(FieldChange $change): string
    {
        return self::YELLOW.'      '.$change->field.': '
            .$this->encode($change->old).' -> '.$this->encode($change->new).self::RESET;
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendDelta(array &$lines, SubListDelta $delta): void
    {
        $lines[] = '      '.$delta->field.':';
        foreach ($delta->added as $member) {
            $lines[] = self::GREEN.'        + '.$this->encode($member).self::RESET;
        }
        foreach ($delta->removed as $member) {
            $lines[] = self::RED.'        - '.$this->encode($member).self::RESET;
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
