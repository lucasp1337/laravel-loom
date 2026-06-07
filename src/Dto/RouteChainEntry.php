<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use PhpParser\Node;

/** A raw route chain captured by RouteChainVisitor (pre-translation). */
final class RouteChainEntry
{
    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $rootArgs
     * @param  list<RouteChainLink>  $chain
     */
    public function __construct(
        public readonly string $rootMethod,
        public readonly array $rootArgs,
        public readonly array $chain,
        public readonly int $line,
    ) {
    }
}
