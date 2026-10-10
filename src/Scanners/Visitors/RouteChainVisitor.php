<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteChainLink;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Support\AstHelpers;
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

        $links = $this->collectChain($node);
        // a dynamic method or class name, so the chain cannot be read
        if ($links === null) {
            return null;
        }

        $root = $links[0];
        // the chain does not start with a route-declaring method (get, match, resource, ...)
        if (! in_array($root['method'], RouterMethod::routeRoots(), true)) {
            return null;
        }
        // a verb call on something other than the Route facade (e.g. a collection's ->get())
        if (! RouteGroupTracker::isRouteReceiver($root['receiver'])) {
            return null;
        }

        $chain = [];
        foreach ($links as $link) {
            $chain[] = new RouteChainLink(method: $link['method'], args: $link['args']);
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
            ...AstHelpers::middlewareList($this->routeLevelMiddleware($chain)),
        ]);

        $this->entries[] = new RouteChainEntry(
            rootMethod: $root['method'],
            rootArgs: $root['args'],
            chain: $chain,
            line: $root['line'],
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
            foreach ($chain[$i]->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $nodes[] = $arg->value;
                }
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

    /**
     * Returns links root-first, or null if malformed. The root link's receiver
     * is the static class; intermediate links chain via `->var`.
     *
     * @return list<array{method: string, args: array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>, receiver: Node\Expr|Node\Name, line: int}>|null
     */
    private function collectChain(Node\Expr $outer): ?array
    {
        $links = [];
        $current = $outer;

        while (true) {
            if ($current instanceof Node\Expr\MethodCall) {
                if (! $current->name instanceof Node\Identifier) {
                    return null;
                }
                array_unshift($links, [
                    'method' => $current->name->toString(),
                    'args' => $current->args,
                    'receiver' => $current->var,
                    'line' => $current->getStartLine(),
                ]);
                $current = $current->var;

                continue;
            }

            if ($current instanceof Node\Expr\StaticCall) {
                if (! $current->name instanceof Node\Identifier) {
                    return null;
                }
                if (! $current->class instanceof Node\Name) {
                    return null;
                }
                array_unshift($links, [
                    'method' => $current->name->toString(),
                    'args' => $current->args,
                    'receiver' => $current->class,
                    'line' => $current->getStartLine(),
                ]);
                // StaticCall is always a chain root.
                break;
            }

            // Non-call receiver (Variable, etc.) — done.
            break;
        }

        return $links === [] ? null : $links;
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
