<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * An entry present on both sides whose semantic fields and/or sublists differ.
 */
final class ChangedEntry
{
    /**
     * @param  list<FieldChange>  $fieldChanges
     * @param  list<SubListDelta>  $subListDeltas
     */
    public function __construct(
        public readonly string $identity,
        public readonly array $fieldChanges,
        public readonly array $subListDeltas,
    ) {
    }
}
