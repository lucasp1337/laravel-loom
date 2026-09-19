<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

final readonly class Page
{
    /** @param  list<object>  $items  read-model objects */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
