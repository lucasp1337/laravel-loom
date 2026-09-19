<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

use Lucasp\Loom\Index\Sections;

/** @internal */
final readonly class NavItem
{
    public function __construct(
        public Sections $section,
        public string $label,
        public int $count,
        public string $url,
    ) {
    }
}
