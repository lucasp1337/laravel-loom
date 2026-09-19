<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

use Closure;
use Lucasp\Loom\Query\SortField;

final readonly class ColumnSpec
{
    /** @param  Closure  $value  receives the read-model item, returns the cell text */
    public function __construct(
        public string $key,
        public string $label,
        public Closure $value,
        public ColumnRole $role = ColumnRole::TEXT,
        public ?SortField $sort = null,
    ) {
    }

    public function cell(object $item): string
    {
        $value = ($this->value)($item);

        return is_int($value) || is_string($value) ? (string) $value : '';
    }
}
