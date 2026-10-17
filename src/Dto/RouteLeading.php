<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Attributes a chain sets before its route call, e.g. the `middleware` and
 * `prefix` of `Route::middleware('auth')->prefix('v1')->get(...)`. Single
 * values are last-wins and middleware replaces, as on the registrar;
 * exclusions accumulate.
 *
 * @internal
 */
final readonly class RouteLeading
{
    /**
     * @param  ?list<string>  $middleware  null when the chain sets none
     * @param  list<string>  $excluded
     */
    public function __construct(
        public ?string $prefix = null,
        public string $name = '',
        public ?string $controller = null,
        public ?array $middleware = null,
        public array $excluded = [],
    ) {}
}
