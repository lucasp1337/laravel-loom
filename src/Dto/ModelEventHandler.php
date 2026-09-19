<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class ModelEventHandler
{
    public function __construct(
        public readonly string $handler,
        public readonly string $method,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
