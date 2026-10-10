<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class ModelEventHandler
{
    public function __construct(
        public string $handler,
        public string $method,
        public string $file,
        public int $line,
    ) {}
}
