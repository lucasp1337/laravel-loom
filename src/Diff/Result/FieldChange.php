<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Result;

/**
 * A single semantic field that differs between the old and new entry.
 *
 * @internal
 */
final readonly class FieldChange
{
    public function __construct(
        public string $field,
        public mixed $old,
        public mixed $new,
    ) {}
}
