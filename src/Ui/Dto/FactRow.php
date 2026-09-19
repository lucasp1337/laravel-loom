<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

final readonly class FactRow
{
    /**
     * @param  list<FactCell>  $cells  text or list rows
     * @param  list<string>  $columns  table rows only
     * @param  list<list<FactCell>>  $table
     */
    public function __construct(
        public string $label,
        public array $cells = [],
        public array $columns = [],
        public array $table = [],
    ) {
    }
}
