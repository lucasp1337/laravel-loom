<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;

final readonly class SectionInfo
{
    public function __construct(
        public Sections $section,
        public int $count,
        public bool $present,
        public bool $listed,
        public ?EntityKind $detailKind,
    ) {
    }
}
