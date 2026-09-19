<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

/** @internal */
final readonly class TableRow
{
    /** @param  list<string>  $cells  one per column */
    public function __construct(
        public array $cells,
        public ?string $url,
        public bool $orphan,
    ) {
    }
}
