<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use PhpParser\Node;

/**
 * Cumulative enclosing `Route::group(...)` context applied to nested routes:
 * the merged prefix segments, name prefix, and default controller.
 */
final class RouteGroupContext
{
    /**
     * @param  list<string>  $prefixSegments  cumulative prefix segments, outermost first
     * @param  string  $namePrefix  cumulative name prefix ('' when none)
     * @param  ?Node\Expr  $controllerNode  nearest enclosing group's default-controller node, resolved to an FQCN at the emit boundary
     */
    public function __construct(
        public readonly array $prefixSegments,
        public readonly string $namePrefix,
        public readonly ?Node\Expr $controllerNode,
    ) {
    }

    /** The empty context used when no group encloses a route. */
    public static function empty(): self
    {
        return new self(prefixSegments: [], namePrefix: '', controllerNode: null);
    }

    /**
     * Merge this cumulative context with one group's own attributes, producing
     * the deeper cumulative frame. The deeper group's controller wins.
     */
    public function merge(RouteGroupAttributes $own): self
    {
        $prefixSegments = $this->prefixSegments;
        if ($own->prefix !== null) {
            $prefixSegments[] = $own->prefix;
        }

        return new self(
            prefixSegments: $prefixSegments,
            namePrefix: $this->namePrefix.($own->name ?? ''),
            controllerNode: $own->controllerNode ?? $this->controllerNode,
        );
    }
}
