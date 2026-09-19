<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Model\Route;

/**
 * A route and the event chain its controller action triggers. `chain` is null
 * for closure or unresolved-controller routes.
 */
final readonly class RouteChain
{
    public function __construct(
        public Route $route,
        public ?MethodChain $chain,
    ) {
    }

    /** @return array<string, mixed> */
    public function routeArray(): array
    {
        return [
            'method' => $this->route->method,
            'uri' => $this->route->uri,
            'name' => $this->route->name,
            'controller_fqcn' => $this->route->controllerFqcn,
            'controller_method' => $this->route->controllerMethod,
            'middleware' => $this->route->middleware,
            'file' => $this->route->file,
            'line' => $this->route->line,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route' => $this->routeArray(),
            'chain' => $this->chain?->toArray(),
        ];
    }
}
