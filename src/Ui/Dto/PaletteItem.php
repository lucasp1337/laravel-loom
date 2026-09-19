<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

use Lucasp\Loom\Ui\NodeType;

/** @internal */
final readonly class PaletteItem
{
    public function __construct(
        public NodeType $type,
        public string $label,
        public string $subtitle,
        public ?string $url,
    ) {
    }
}
