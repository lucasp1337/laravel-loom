<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteChainLink;
use Lucasp\Loom\Dto\RouteGroupAttributes;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Support\AstHelpers;
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

    /**
     * Cumulative enclosing `Route::group(...)` frames. The top is the context
     * an inner leaf route inherits.
     *
     * @var list<RouteGroupContext>
     */
    private array $groupStack = [];

    /** @var list<RouteChainEntry> */
    private array $entries = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->parentStack = [];
        $this->groupStack = [];
        $this->entries = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        // PhpParser enters a group call before descending into its closure, so
        // pushing here keeps the stack top correct for inner leaf routes.
        if ($this->isGroupOpener($node)) {
            $this->groupStack[] = $this->currentGroupContext()->merge($this->parseGroupAttributes($node));
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->parentStack);

        // Same predicate as the push so the stack balances exactly.
        if ($this->isGroupOpener($node)) {
            array_pop($this->groupStack);
        }

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

        $context = $this->currentGroupContext();

        // Resolve the group controller here, not at push time: NameResolver only
        // rewrites the controller's name node once traversal descends into it,
        // which is after this group's enterNode but before this leaf is reached.
        $groupController = $context->controllerNode !== null
            ? AstHelpers::classConstFqcn($context->controllerNode)
            : null;

        $this->entries[] = new RouteChainEntry(
            rootMethod: $root['method'],
            rootArgs: $root['args'],
            chain: $chain,
            line: $root['line'],
            groupPrefix: $context->prefixSegments,
            groupNamePrefix: $context->namePrefix,
            groupController: $groupController,
        );

        return null;
    }

    private function currentGroupContext(): RouteGroupContext
    {
        if ($this->groupStack === []) {
            return RouteGroupContext::empty();
        }

        return $this->groupStack[count($this->groupStack) - 1];
    }

    /**
     * A "group opener" is a `group` call rooting at the `Route` facade, in
     * either the array-config (`Route::group([...], fn)`) or fluent
     * (`Route::prefix('x')->...->group(fn)`) form.
     */
    private function isGroupOpener(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'group'
                && $node->class instanceof Node\Name
                && $this->isRouteReceiver($node->class);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'group'
                && $this->fluentRootIsRoute($node->var);
        }

        return false;
    }

    /** Walk a fluent `->var` chain to its root and check it is the Route facade. */
    private function fluentRootIsRoute(Node\Expr $expr): bool
    {
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
            return $this->isRouteReceiver($expr->class);
        }

        return false;
    }

    /** Parse one group call's OWN attributes (prefix / name / controller). */
    private function parseGroupAttributes(Node $node): RouteGroupAttributes
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $config = $node->args[0] ?? null;
            if ($config instanceof Node\Arg && $config->value instanceof Node\Expr\Array_) {
                return $this->parseArrayConfig($config->value);
            }

            return new RouteGroupAttributes(prefix: null, name: null, controllerNode: null);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            return $this->parseFluentConfig($node->var);
        }

        return new RouteGroupAttributes(prefix: null, name: null, controllerNode: null);
    }

    /** Read prefix/as/controller keys from a `Route::group([...], fn)` assoc array. */
    private function parseArrayConfig(Node\Expr\Array_ $array): RouteGroupAttributes
    {
        $prefix = null;
        $name = null;
        $controllerNode = null;

        foreach ($array->items as $item) {
            $key = AstHelpers::scalarString($item->key);
            if ($key === null) {
                continue;
            }

            if ($key === 'prefix') {
                $prefix = $this->normalisePrefix(AstHelpers::scalarString($item->value));
            } elseif ($key === 'as') {
                $name = $this->normaliseName(AstHelpers::scalarString($item->value));
            } elseif ($key === 'controller') {
                $controllerNode = $item->value;
            }
        }

        return new RouteGroupAttributes(prefix: $prefix, name: $name, controllerNode: $controllerNode);
    }

    /**
     * Read prefix/name/as/controller setters from a fluent group's `->var`
     * chain (e.g. `Route::prefix('x')->name('y.')->controller(C::class)`).
     */
    private function parseFluentConfig(Node\Expr $chain): RouteGroupAttributes
    {
        $prefix = null;
        $name = null;
        $controllerNode = null;

        $current = $chain;
        while (true) {
            if ($current instanceof Node\Expr\MethodCall) {
                $nameNode = $current->name;
                $arg = $current->args[0] ?? null;
                $next = $current->var;
            } elseif ($current instanceof Node\Expr\StaticCall) {
                // The fluent chain's terminal setter is a static call on the
                // Route facade (e.g. Route::prefix('x')->...). Read it too.
                $nameNode = $current->name;
                $arg = $current->args[0] ?? null;
                $next = null;
            } else {
                break;
            }

            if ($nameNode instanceof Node\Identifier) {
                $method = $nameNode->toString();
                $value = $arg instanceof Node\Arg ? $arg->value : null;

                if ($method === 'prefix') {
                    $prefix ??= $this->normalisePrefix(AstHelpers::scalarString($value));
                } elseif ($method === 'name' || $method === 'as') {
                    $name ??= $this->normaliseName(AstHelpers::scalarString($value));
                } elseif ($method === 'controller') {
                    $controllerNode ??= $value;
                }
            }

            if ($next === null) {
                break;
            }
            $current = $next;
        }

        return new RouteGroupAttributes(prefix: $prefix, name: $name, controllerNode: $controllerNode);
    }

    /** Trim wrapping slashes; treat empty as absent. */
    private function normalisePrefix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value, '/');

        return $trimmed === '' ? null : $trimmed;
    }

    /** Keep the name as written (may end in '.'); treat empty as absent. */
    private function normaliseName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
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
