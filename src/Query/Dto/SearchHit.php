<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\EntityKind;

/** @internal */
final readonly class SearchHit
{
    /**
     * @param  string  $subtitle  `file:line`
     * @param  string  $detailRef  FQCN, `VERB uri` for routes, or `file:line` for closures
     */
    public function __construct(
        public Sections $section,
        public ?EntityKind $kind,
        public string $label,
        public string $subtitle,
        public int $score,
        public string $detailRef,
    ) {
    }
}
