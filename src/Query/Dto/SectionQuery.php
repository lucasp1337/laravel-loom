<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Query\SortDirection;
use Lucasp\Loom\Query\SortField;

/** @internal */
final readonly class SectionQuery
{
    /**
     * @param  ?string  $search  case-insensitive substring over the item's name and file
     * @param  array<string, scalar>  $filters  exact match on a read-model property; unknown properties match nothing
     */
    public function __construct(
        public ?string $search = null,
        public array $filters = [],
        public ?SortField $sort = null,
        public SortDirection $dir = SortDirection::ASC,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }
}
