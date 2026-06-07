<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteChainLink;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Captures `Route` facade route chains (e.g. `Route::get(...)->name(...)`).
 * Only chains whose root static call is an HTTP-verb router method are kept.
 */
final class RouteChainVisitor extends NodeVisitorAbstract
{
    /** @var array<int, Node> */
    private array $parentStack = [];

    /** @var list<RouteChainEntry> */
    private array $entries = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->parentStack = [];
        $this->entries = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->parentStack);

        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        // Emit only at the outermost call in a chain.
        $parent = $this->currentParent();
        if ($parent instanceof Node\Expr\MethodCall && $parent->var === $node) {
            return null;
        }

        $links = $this->collectChain($node);
        if ($links === null) {
            return null;
        }

        $root = $links[0];
        if (! in_array($root['method'], RouterMethod::routeRoots(), true)) {
            return null;
        }
        if (! $this->isRouteReceiver($root['receiver'])) {
            return null;
        }

        $chain = [];
        foreach ($links as $link) {
            $chain[] = new RouteChainLink(method: $link['method'], args: $link['args']);
        }

        $this->entries[] = new RouteChainEntry(
            rootMethod: $root['method'],
            rootArgs: $root['args'],
            chain: $chain,
            line: $root['line'],
        );

        return null;
    }

    /**
     * Returns links root-first, or null if malformed. The root link's receiver
     * is the static class; intermediate links chain via `->var`.
     *
     * @return list<array{method: string, args: array<int, Node\Arg|Node\VariadicPlaceholder>, receiver: Node\Expr|Node\Name, line: int}>|null
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

    private function isRouteReceiver(Node $receiver): bool
    {
        if (! $receiver instanceof Node\Name) {
            return false;
        }

        $resolved = $receiver->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString() === Facades::ROUTE->value;
        }

        // Fallback when NameResolver didn't attach a resolved name.
        return Facades::ROUTE->matches($receiver->toString());
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
