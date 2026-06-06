<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * A single semantic field that differs between the old and new entry.
 */
final class FieldChange
{
    public function __construct(
        public readonly string $field,
        public readonly mixed $old,
        public readonly mixed $new,
    ) {
    }
}
