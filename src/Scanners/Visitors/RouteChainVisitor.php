<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteChainLink;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\RouteGroupAttribute;
use Lucasp\Loom\Support\RouteGroupTracker;
use PhpParser\Node;

/**
 * Captures `Route` facade route chains (e.g. `Route::get(...)->name(...)`).
 * Only chains whose root static call is an HTTP-verb router method are kept.
 *
 * @internal
 */
final class RouteChainVisitor extends CollectingVisitor
{
    /** @var array<int, Node> */
    private array $parentStack = [];

    private RouteGroupTracker $groups;

    /** @var list<RouteChainEntry> */
    private array $entries = [];

    /**
     * @param  ?RouteGroupContext  $inherited  attributes of the loading call, applied around every route of the file
     */
    public function __construct(?RouteGroupContext $inherited = null)
    {
        $this->groups = new RouteGroupTracker($inherited);
    }

    protected function reset(): void
    {
        $this->parentStack = [];
        $this->groups->reset();
        $this->entries = [];
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        $this->groups->enter($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->parentStack);

        $this->groups->leave($node);

        // only calls can root or continue a route chain
        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        // Emit only at the outermost call in a chain.
        $parent = $this->currentParent();
        if ($parent instanceof Node\Expr\MethodCall && $parent->var === $node) {
            return null;
        }

        $callChain = CallChain::from($node);
        // a dynamic method or class name, so the chain cannot be read
        if ($callChain === null) {
            return null;
        }

        $root = $callChain->root();
        $rootMethod = (string) $root->name();
        // the chain does not start with a route-declaring method (get, match, resource, ...)
        if (! in_array($rootMethod, RouterMethod::routeRoots(), true)) {
            return null;
        }
        // a verb call on something other than the Route facade (e.g. a collection's ->get())
        $receiver = $root->receiver();
        if ($receiver === null || ! RouteGroupTracker::isRouteReceiver($receiver)) {
            return null;
        }

        $chain = [];
        foreach ($callChain->links() as $link) {
            $chain[] = new RouteChainLink(method: (string) $link->name(), args: $link->args());
        }

        $context = $this->groups->current();

        // Resolve the group controller here, not at push time: NameResolver only
        // rewrites the controller's name node once traversal descends into it,
        // which is after this group's enterNode but before this leaf is reached.
        $groupController = $context->controller();

        // Resolve middleware here for the same reason as the controller: a
        // `Foo::class` middleware needs NameResolver, which has run by now.
        // Group middleware (outermost-first) precedes route-level middleware.
        $middleware = $this->dedupe([
            ...$context->middleware(),
            ...ValueLists::middleware($this->routeLevelMiddleware($chain)),
        ]);

        $this->entries[] = new RouteChainEntry(
            rootMethod: $rootMethod,
            rootArgs: $root->args(),
            chain: $chain,
            line: $root->line(),
            groupPrefix: $context->prefixSegments,
            groupNamePrefix: $context->namePrefix,
            groupController: $groupController,
            middleware: $middleware,
        );

        return null;
    }

    /**
     * Collect `->middleware(<args>)` argument nodes from a route's own chain,
     * in source order, flattening variadic args. Index 0 is the root call.
     *
     * @param  list<RouteChainLink>  $chain
     * @return list<Node\Expr>
     */
    private function routeLevelMiddleware(array $chain): array
    {
        $nodes = [];
        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            // a modifier other than ->middleware()
            if ($chain[$i]->method !== RouteGroupAttribute::MIDDLEWARE->value) {
                continue;
            }
            foreach ($chain[$i]->args->values() as $value) {
                $nodes[] = $value;
            }
        }

        return $nodes;
    }

    /**
     * Drop exact-string duplicates, preserving first occurrence.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function dedupe(array $names): array
    {
        $seen = [];
        $out = [];
        foreach ($names as $name) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $name;
        }

        return $out;
    }

    private function currentParent(): ?Node
    {
        if ($this->parentStack === []) {
            return null;
        }

        return $this->parentStack[count($this->parentStack) - 1];
    }

    /** @return list<RouteChainEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
