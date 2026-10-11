<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Regions;

/** An open/close marker pair in a page; offsets delimit the text between them. */
final readonly class MarkerPair
{
    public function __construct(
        public RegionName $name,
        public int $bodyStart,
        public int $bodyEnd,
    ) {}
}
