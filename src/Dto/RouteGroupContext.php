<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\RouteGroupAttribute;
use PhpParser\Node;

/**
 * Cumulative enclosing `Route::group(...)` context applied to nested routes:
 * the merged prefix segments, name prefix, default controller and middleware.
 *
 * A context is either source-bound (carrying AST nodes of the file it was read
 * from, resolved lazily) or {@see resolved()} (plain strings), which is the form
 * that crosses from a loading call into the file it loads.
 *
 * @internal
 */
final readonly class RouteGroupContext
{
    /**
     * @param  list<string>  $prefixSegments  cumulative prefix segments, outermost first
     * @param  string  $namePrefix  cumulative name prefix ('' when none)
     * @param  ?Node\Expr  $controllerNode  nearest enclosing group's default-controller node, resolved to an FQCN at the emit boundary
     * @param  list<Node\Expr>  $middlewareNodes  cumulative middleware argument nodes, outermost first; resolved to strings at the emit boundary
     * @param  ?string  $inheritedController  default controller FQCN inherited from a loading call, used when no group in this file sets one
     * @param  list<string>  $inheritedMiddleware  middleware inherited from a loading call, applied before this file's own
     * @param  list<RouteGroupAttribute>  $unresolved  attributes of the enclosing groups that were not static literals
     */
    public function __construct(
        public array $prefixSegments,
        public string $namePrefix,
        public ?Node\Expr $controllerNode,
        public array $middlewareNodes = [],
        public ?string $inheritedController = null,
        public array $inheritedMiddleware = [],
        public array $unresolved = [],
    ) {}

    /** The empty context used when no group encloses a route. */
    public static function empty(): self
    {
        return new self(prefixSegments: [], namePrefix: '', controllerNode: null);
    }

    /**
     * Merge this cumulative context with one group's own attributes, producing
     * the deeper cumulative frame. The deeper group's controller wins; its
     * middleware is appended after the inherited middleware (outermost first).
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
            middlewareNodes: [...$this->middlewareNodes, ...$own->middlewareNodes],
            inheritedController: $own->controllerNode !== null ? null : $this->inheritedController,
            inheritedMiddleware: $this->inheritedMiddleware,
            unresolved: [...$this->unresolved, ...$own->unresolved],
        );
    }

    /**
     * This context as seen by a file loaded from inside `$outer`: the outer
     * attributes come first, exactly as Laravel stacks the groups around a
     * required file.
     */
    public function within(self $outer): self
    {
        $outer = $outer->resolved();

        return new self(
            prefixSegments: [...$outer->prefixSegments, ...$this->prefixSegments],
            namePrefix: $outer->namePrefix.$this->namePrefix,
            controllerNode: $this->controllerNode,
            middlewareNodes: $this->middlewareNodes,
            inheritedController: $this->controllerNode !== null ? null : ($this->inheritedController ?? $outer->inheritedController),
            inheritedMiddleware: [...$outer->inheritedMiddleware, ...$this->inheritedMiddleware],
            unresolved: $this->unresolved,
        );
    }

    /** Replace AST nodes with their resolved strings so the context outlives its file's traversal. */
    public function resolved(): self
    {
        return new self(
            prefixSegments: $this->prefixSegments,
            namePrefix: $this->namePrefix,
            controllerNode: null,
            middlewareNodes: [],
            inheritedController: $this->controller(),
            inheritedMiddleware: $this->middleware(),
            unresolved: $this->unresolved,
        );
    }

    /** Default controller FQCN; call only once the file's names have been resolved. */
    public function controller(): ?string
    {
        return $this->controllerNode !== null
            ? ClassRef::fromClassConstant($this->controllerNode)
            : $this->inheritedController;
    }

    /**
     * Group middleware, outermost first; call only once the file's names have
     * been resolved.
     *
     * @return list<string>
     */
    public function middleware(): array
    {
        return [...$this->inheritedMiddleware, ...ValueLists::middleware($this->middlewareNodes)];
    }

    /** Stable identity of the applied attributes, for deduplicating contexts. */
    public function key(): string
    {
        $resolved = $this->resolved();

        return json_encode([
            $resolved->prefixSegments,
            $resolved->namePrefix,
            $resolved->inheritedController,
            $resolved->inheritedMiddleware,
        ], JSON_THROW_ON_ERROR);
    }
}
