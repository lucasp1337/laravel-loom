<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A single HTTP route discovered under routes/. */
final class RouteEntry
{
    /**
     * @param  list<string>  $middleware  resolved middleware chain (group then route-level), deduped
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly ?string $name,
        public readonly ?string $controllerFqcn,
        public readonly ?string $controllerMethod,
        public readonly array $middleware,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
