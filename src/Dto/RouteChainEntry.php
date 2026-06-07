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
     * @param  list<string>  $groupPrefix  cumulative enclosing-group prefix segments
     * @param  string  $groupNamePrefix  cumulative enclosing-group name prefix ('' when none)
     * @param  ?string  $groupController  nearest enclosing-group default controller FQCN
     * @param  list<string>  $middleware  resolved middleware chain (group then route-level), deduped
     */
    public function __construct(
        public readonly string $rootMethod,
        public readonly array $rootArgs,
        public readonly array $chain,
        public readonly int $line,
        public readonly array $groupPrefix,
        public readonly string $groupNamePrefix,
        public readonly ?string $groupController,
        public readonly array $middleware,
    ) {
    }
}
