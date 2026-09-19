<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

/** @internal */
final readonly class PaletteGroup
{
    /** @param  list<PaletteItem>  $items */
    public function __construct(
        public string $label,
        public array $items,
    ) {
    }
}
