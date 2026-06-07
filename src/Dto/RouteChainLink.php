<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use PhpParser\Node;

/** One link in a `Route::get(...)->name(...)` chain. */
final class RouteChainLink
{
    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
    ) {
    }
}
