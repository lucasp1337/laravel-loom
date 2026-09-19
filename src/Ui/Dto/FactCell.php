<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Dto;

final readonly class FactCell
{
    public function __construct(
        public string $text,
        public ?string $url,
    ) {
    }
}
