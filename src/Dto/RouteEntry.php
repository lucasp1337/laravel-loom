<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A single HTTP route discovered under routes/.
 *
 * @internal
 */
final readonly class RouteEntry
{
    /**
     * @param  list<string>  $middleware  resolved middleware chain (group then route-level), deduped
     * @param  list<array<string, mixed>>  $dispatches  dispatch sites in the controller method; populated by the cross-link pass
     */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public ?string $controllerFqcn,
        public ?string $controllerMethod,
        public array $middleware,
        public string $file,
        public int $line,
        public array $dispatches = [],
        /** Last line of a closure action; null for every other action. */
        public ?int $endLine = null,
    ) {}
}
