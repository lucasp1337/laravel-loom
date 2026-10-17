<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Support\Ast\Args;

/**
 * A raw route chain captured by RouteChainVisitor (pre-translation).
 *
 * @internal
 */
final readonly class RouteChainEntry
{
    /**
     * @param  list<ChainLink>  $chain  the route call first, then the modifiers written after it
     * @param  string  $expression  the route call rendered as source, for reports
     * @param  list<string>  $groupPrefix  cumulative enclosing-group prefix segments
     * @param  string  $groupNamePrefix  cumulative enclosing-group name prefix ('' when none)
     * @param  ?string  $controller  default controller FQCN: the chain's own, else the nearest group's
     * @param  list<string>  $groupMiddleware  middleware of the enclosing groups
     * @param  list<string>  $groupExcluded  `withoutMiddleware` of the enclosing groups
     * @param  list<string>  $middleware  effective middleware of an ordinary route: groups, leading attributes and `->middleware()`, minus every exclusion, deduped
     * @param  list<RouteIssue>  $issues  unreadable entries met while reading the chain's attributes
     */
    public function __construct(
        public RouterMethod $root,
        public Args $rootArgs,
        public array $chain,
        public int $line,
        public string $expression,
        public array $groupPrefix,
        public string $groupNamePrefix,
        public ?string $controller,
        public array $groupMiddleware,
        public array $groupExcluded,
        public RouteLeading $leading,
        public array $middleware,
        public array $issues = [],
    ) {}
}
