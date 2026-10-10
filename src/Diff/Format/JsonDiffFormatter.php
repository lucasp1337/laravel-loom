<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

use Illuminate\Support\Arr;
use Lucasp\Loom\Diff\Result\ChangedEntry;
use Lucasp\Loom\Diff\Result\DiffResult;
use Lucasp\Loom\Diff\Result\FieldChange;
use Lucasp\Loom\Diff\Result\SectionDiff;
use Lucasp\Loom\Diff\Result\SubListDelta;

/**
 * Machine-readable diff: a plain-array projection encoded as pretty JSON.
 * Empty sections are omitted; an all-empty diff renders as `{}`.
 *
 * @internal
 */
final class JsonDiffFormatter implements DiffFormatter
{
    public function format(DiffResult $result): string
    {
        $out = [];
        foreach ($result->sections() as $section => $diff) {
            if ($diff->isEmpty()) {
                continue;
            }
            $out[$section] = $this->section($diff);
        }

        // Force an object even when empty so an all-empty diff renders `{}`.
        return (string) json_encode((object) $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string,mixed>
     */
    private function section(SectionDiff $diff): array
    {
        return [
            'added' => $diff->added,
            'removed' => $diff->removed,
            'changed' => Arr::map($diff->changed, fn (ChangedEntry $entry): array => $this->changed($entry)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function changed(ChangedEntry $entry): array
    {
        return [
            'identity' => $entry->identity,
            'field_changes' => Arr::map($entry->fieldChanges, static fn (FieldChange $change): array => [
                'field' => $change->field,
                'old' => $change->old,
                'new' => $change->new,
            ]),
            'sublist_changes' => Arr::map($entry->subListDeltas, static fn (SubListDelta $delta): array => [
                'field' => $delta->field,
                'added' => $delta->added,
                'removed' => $delta->removed,
            ]),
        ];
    }
}
