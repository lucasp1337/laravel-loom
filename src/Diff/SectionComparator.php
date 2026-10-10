<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff;

use Illuminate\Support\Arr;
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
 *
 * @internal
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
            if (! Arr::exists($oldMap, $identity)) {
                $added[$identity] = $entry;
            }
        }
        foreach ($oldMap as $identity => $entry) {
            if (! Arr::exists($newMap, $identity)) {
                $removed[$identity] = $entry;
            }
        }
        foreach ($oldMap as $identity => $oldEntry) {
            if (! Arr::exists($newMap, $identity)) {
                continue;
            }
            $entry = $this->compareEntry($spec, (string) $identity, $oldEntry, $newMap[$identity]);
            if ($entry !== null) {
                $changed[] = $entry;
            }
        }

        $added = collect($added)->sortKeys()->all();
        $removed = collect($removed)->sortKeys()->all();
        $changed = array_values(collect($changed)->sort(static fn (ChangedEntry $a, ChangedEntry $b): int => strcmp($a->identity, $b->identity))->all());

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
            $oldValue = $oldEntry[$field->value] ?? null;
            $newValue = $newEntry[$field->value] ?? null;
            if ($oldValue !== $newValue) {
                $changes[] = new FieldChange($field->value, $oldValue, $newValue);
            }
        }
        $changes = array_values(collect($changes)->sort(static fn (FieldChange $a, FieldChange $b): int => strcmp($a->field, $b->field))->all());

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
            $delta = $this->subListDelta($subList, $oldEntry[$subList->field->value] ?? [], $newEntry[$subList->field->value] ?? []);
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
            if (! Arr::exists($oldMap, $key)) {
                $added[$key] = $member;
            }
        }
        foreach ($oldMap as $key => $member) {
            if (! Arr::exists($newMap, $key)) {
                $removed[$key] = $member;
            }
        }

        if ($added === [] && $removed === []) {
            return null;
        }

        $added = collect($added)->sortKeys()->all();
        $removed = collect($removed)->sortKeys()->all();

        return new SubListDelta($subList->field->value, array_values($added), array_values($removed));
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
            $normalized = is_array($member) ? $member : [SubListDelta::SCALAR_MEMBER_KEY => $member];
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
