<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * An entry present on both sides whose semantic fields and/or sublists differ.
 *
 * @internal
 */
final readonly class ChangedEntry
{
    /**
     * @param  list<FieldChange>  $fieldChanges
     * @param  list<SubListDelta>  $subListDeltas
     */
    public function __construct(
        public string $identity,
        public array $fieldChanges,
        public array $subListDeltas,
    ) {}
}
