<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\Ast\Args;

/**
 * One link in a `Route::get(...)->name(...)` chain.
 *
 * @internal
 */
final class RouteChainLink
{
    public function __construct(
        public readonly string $method,
        public readonly Args $args,
    ) {}
}
