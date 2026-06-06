<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff;

use Lucasp\Loom\Diff\Result\ChangedEntry;
use Lucasp\Loom\Diff\Result\FieldChange;
use Lucasp\Loom\Diff\Result\SectionDiff;
use Lucasp\Loom\Diff\Result\SubListDelta;
use Lucasp\Loom\Diff\Spec\SectionDiffSpec;
use Lucasp\Loom\Diff\Spec\SubListSpec;

/**
 * Generic, spec-driven engine that diffs one section. It knows nothing about
 * any particular section — all section knowledge lives in the
 * {@see SectionDiffSpec} it is handed. Output is deterministic regardless of
 * input order.
 */
final class SectionComparator
{
    /**
     * @param  list<array<string,mixed>>  $old
     * @param  list<array<string,mixed>>  $new
     */
    public function compare(SectionDiffSpec $spec, array $old, array $new): SectionDiff
    {
        $oldMap = $this->indexByIdentity($spec, $old);
        $newMap = $this->indexByIdentity($spec, $new);

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($newMap as $identity => $entry) {
            if (! array_key_exists($identity, $oldMap)) {
                $added[$identity] = $entry;
            }
        }
        foreach ($oldMap as $identity => $entry) {
            if (! array_key_exists($identity, $newMap)) {
                $removed[$identity] = $entry;
            }
        }
        foreach ($oldMap as $identity => $oldEntry) {
            if (! array_key_exists($identity, $newMap)) {
                continue;
            }
            $entry = $this->compareEntry($spec, (string) $identity, $oldEntry, $newMap[$identity]);
            if ($entry !== null) {
                $changed[] = $entry;
            }
        }

        ksort($added);
        ksort($removed);
        usort($changed, static fn (ChangedEntry $a, ChangedEntry $b): int => strcmp($a->identity, $b->identity));

        return new SectionDiff(array_values($added), array_values($removed), $changed);
    }

    /**
     * @param  array<string,mixed>  $oldEntry
     * @param  array<string,mixed>  $newEntry
     */
    private function compareEntry(SectionDiffSpec $spec, string $identity, array $oldEntry, array $newEntry): ?ChangedEntry
    {
        $fieldChanges = $this->fieldChanges($spec, $oldEntry, $newEntry);
        $subListDeltas = $this->subListDeltas($spec, $oldEntry, $newEntry);

        if ($fieldChanges === [] && $subListDeltas === []) {
            return null;
        }

        return new ChangedEntry($identity, $fieldChanges, $subListDeltas);
    }

    /**
     * @param  array<string,mixed>  $oldEntry
     * @param  array<string,mixed>  $newEntry
     * @return list<FieldChange>
     */
    private function fieldChanges(SectionDiffSpec $spec, array $oldEntry, array $newEntry): array
    {
        $changes = [];
        foreach ($spec->semanticFields as $field) {
            // Absent optional fields normalize to null on both sides; an
            // object-or-null (queue_config) or ordered list (channels) is
            // compared deep + order-sensitive by PHP's strict array compare.
            $oldValue = $oldEntry[$field] ?? null;
            $newValue = $newEntry[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[] = new FieldChange($field, $oldValue, $newValue);
            }
        }
        usort($changes, static fn (FieldChange $a, FieldChange $b): int => strcmp($a->field, $b->field));

        return $changes;
    }

    /**
     * @param  array<string,mixed>  $oldEntry
     * @param  array<string,mixed>  $newEntry
     * @return list<SubListDelta>
     */
    private function subListDeltas(SectionDiffSpec $spec, array $oldEntry, array $newEntry): array
    {
        $deltas = [];
        foreach ($spec->subLists as $subList) {
            $delta = $this->subListDelta($subList, $oldEntry[$subList->field] ?? [], $newEntry[$subList->field] ?? []);
            if ($delta !== null) {
                $deltas[] = $delta;
            }
        }

        return $deltas;
    }

    private function subListDelta(SubListSpec $subList, mixed $old, mixed $new): ?SubListDelta
    {
        $oldMap = $this->indexMembers($subList, $old);
        $newMap = $this->indexMembers($subList, $new);

        $added = [];
        $removed = [];
        foreach ($newMap as $key => $member) {
            if (! array_key_exists($key, $oldMap)) {
                $added[$key] = $member;
            }
        }
        foreach ($oldMap as $key => $member) {
            if (! array_key_exists($key, $newMap)) {
                $removed[$key] = $member;
            }
        }

        if ($added === [] && $removed === []) {
            return null;
        }

        ksort($added);
        ksort($removed);

        return new SubListDelta($subList->field, array_values($added), array_values($removed));
    }

    /**
     * Index a sublist's members by their identity key. Scalar members (plain
     * strings) are wrapped as `['value' => member]` only to compute the identity
     * key; the original member (scalar or array) is what gets emitted in the
     * delta.
     *
     * @return array<string,mixed>
     */
    private function indexMembers(SubListSpec $subList, mixed $members): array
    {
        $identity = $subList->memberIdentity;
        $map = [];
        if (! is_array($members)) {
            return $map;
        }
        foreach ($members as $member) {
            $normalized = is_array($member) ? $member : ['value' => $member];
            $map[($identity)($normalized)] = $member;
        }

        return $map;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,array<string,mixed>>
     */
    private function indexByIdentity(SectionDiffSpec $spec, array $entries): array
    {
        $identity = $spec->identity;
        $map = [];
        foreach ($entries as $entry) {
            $map[($identity)($entry)] = $entry;
        }

        return $map;
    }
}
