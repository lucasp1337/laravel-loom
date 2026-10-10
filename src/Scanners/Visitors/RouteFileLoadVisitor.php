<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Support\Facades;
use Lucasp\Loom\Support\RouteFileLoader;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the path arguments of calls that load route files:
 * `$this->loadRoutesFrom($path)`, `Route::group($attributes, $path)`,
 * `Route::...->group($path)`, and `withRouting(web:, api:, commands:)` /
 * `withCommands([...])` on the application builder. Closures are not paths and
 * are skipped; an array argument yields one reference per element.
 *
 * @internal
 */
final class RouteFileLoadVisitor extends NodeVisitorAbstract
{
    /** `ApplicationBuilder::withRouting()` parameters by position. */
    private const ROUTING_PARAMETERS = ['using', 'web', 'api', 'commands', 'channels', 'pages', 'health', 'apiPrefix', 'then'];

    /** @var list<RouteFileReference> */
    private array $references = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->references = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $this->staticCall($node);
        } elseif ($node instanceof Node\Expr\MethodCall) {
            $this->methodCall($node);
        }

        return null;
    }

    /** @return list<RouteFileReference> */
    public function getReferences(): array
    {
        return $this->references;
    }

    private function staticCall(Node\Expr\StaticCall $call): void
    {
        if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'group') {
            return;
        }
        if (! $call->class instanceof Node\Name || ! $this->isRouteFacade($call->class)) {
            return;
        }

        // Router::group(array $attributes, Closure|array|string $routes)
        $this->collect(RouteFileLoader::ROUTE_GROUP, $call->args[1] ?? null);
    }

    private function methodCall(Node\Expr\MethodCall $call): void
    {
        if (! $call->name instanceof Node\Identifier) {
            return;
        }

        match ($call->name->toString()) {
            'loadRoutesFrom' => $this->loadRoutesFrom($call),
            'group' => $this->fluentGroup($call),
            'withRouting' => $this->withRouting($call),
            'withCommands' => $this->collect(RouteFileLoader::WITH_COMMANDS, $call->args[0] ?? null, allowsDirectory: true),
            default => null,
        };
    }

    private function loadRoutesFrom(Node\Expr\MethodCall $call): void
    {
        if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
            $this->collect(RouteFileLoader::LOAD_ROUTES_FROM, $call->args[0] ?? null);
        }
    }

    /** RouteRegistrar::group(Closure|array|string $callback) behind a `Route::` chain. */
    private function fluentGroup(Node\Expr\MethodCall $call): void
    {
        $root = $call->var;
        while ($root instanceof Node\Expr\MethodCall) {
            $root = $root->var;
        }

        if ($root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name && $this->isRouteFacade($root->class)) {
            $this->collect(RouteFileLoader::ROUTE_GROUP, $call->args[0] ?? null);
        }
    }

    private function withRouting(Node\Expr\MethodCall $call): void
    {
        foreach ($call->args as $position => $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) {
                continue;
            }

            $parameter = $arg->name?->toString() ?? (self::ROUTING_PARAMETERS[$position] ?? null);

            match ($parameter) {
                'web', 'api' => $this->collect(RouteFileLoader::WITH_ROUTING, $arg),
                'commands' => $this->collect(RouteFileLoader::WITH_ROUTING, $arg, allowsDirectory: true),
                default => null,
            };
        }
    }

    private function collect(RouteFileLoader $loader, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder|null $arg, bool $allowsDirectory = false): void
    {
        if (! $arg instanceof Node\Arg) {
            return;
        }

        $value = $arg->value;
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                $this->add($loader, $item->value, $allowsDirectory);
            }

            return;
        }

        $this->add($loader, $value, $allowsDirectory);
    }

    private function add(RouteFileLoader $loader, Node\Expr $value, bool $allowsDirectory): void
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            return;
        }
        if ($value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null') {
            return;
        }

        $this->references[] = new RouteFileReference($loader, $value, $value->getStartLine(), $allowsDirectory);
    }

    private function isRouteFacade(Node\Name $name): bool
    {
        $resolved = $name->getAttribute('resolvedName');

        return Facades::ROUTE->matches($resolved instanceof Node\Name ? $resolved->toString() : $name->toString());
    }
}
