<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

final readonly class IndexMeta
{
    public function __construct(
        public string $loomVersion,
        public string $scannedAt,
        public string $laravelVersion,
    ) {
    }
}
