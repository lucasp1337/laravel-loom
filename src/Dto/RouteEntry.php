<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A single HTTP route discovered under routes/. */
final class RouteEntry
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly ?string $name,
        public readonly ?string $controllerFqcn,
        public readonly ?string $controllerMethod,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
