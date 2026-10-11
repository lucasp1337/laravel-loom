<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\RouteGroupAttribute;
use PhpParser\Node;

/**
 * One `Route::group(...)`'s own attributes, before merging with parents.
 *
 * @internal
 */
final readonly class RouteGroupAttributes
{
    /**
     * @param  ?string  $prefix  trimmed prefix segment ('/'-stripped); null when absent/empty
     * @param  ?string  $name  name prefix as written (may end in '.'); null when absent/empty
     * @param  ?Node\Expr  $controllerNode  the default-controller `Class::class` node, or null when absent. Resolved to an FQCN at the emit boundary (in leaveNode), after NameResolver has rewritten the name — reading it earlier yields the unresolved short name.
     * @param  list<Node\Expr>  $middlewareNodes  this group's own middleware argument nodes (verbatim). Resolved to strings at the emit boundary for the same NameResolver reason as $controllerNode.
     * @param  list<RouteGroupAttribute>  $unresolved  attributes present in the source whose value is not a static literal
     */
    public function __construct(
        public ?string $prefix,
        public ?string $name,
        public ?Node\Expr $controllerNode,
        public array $middlewareNodes = [],
        public array $unresolved = [],
    ) {}
}
