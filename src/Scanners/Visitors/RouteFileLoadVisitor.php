<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Support\Ast\Arg;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\RouteFileLoader;
use Lucasp\Loom\Support\RouteGroupAttribute;
use Lucasp\Loom\Support\RouteGroupTracker;
use Lucasp\Loom\Support\RoutingMiddlewareGroup;
use Lucasp\Loom\Support\RoutingParameter;
use PhpParser\Node;

/**
 * Collects the path arguments of calls that load route files:
 * `$this->loadRoutesFrom($path)`, `Route::group($attributes, $path)`,
 * `Route::...->group($path)`, and `withRouting(web:, api:, commands:)` /
 * `withCommands([...])` on the application builder. Closures are not paths and
 * are skipped; an array argument yields one reference per element.
 *
 * Each reference carries the group attributes in force at the call: the
 * enclosing `Route::...->group()` frames, the call's own group, or the
 * `web` / `api` wrapper `withRouting()` applies.
 *
 * @internal
 */
final class RouteFileLoadVisitor extends CollectingVisitor
{
    /** @var list<RouteFileReference> */
    private array $references = [];

    private RouteGroupTracker $groups;

    public function __construct()
    {
        $this->groups = new RouteGroupTracker;
    }

    protected function reset(): void
    {
        $this->references = [];
        $this->groups->reset();
    }

    public function enterNode(Node $node): null
    {
        // Enter first: a group call that loads a path applies its own attributes to it.
        $this->groups->enter($node);

        match (true) {
            // Route::group($attributes, $path)
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node),
            // $this->loadRoutesFrom(), Route::...->group(), ->withRouting(), ->withCommands()
            $node instanceof Node\Expr\MethodCall => $this->methodCall($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->groups->leave($node);

        return null;
    }

    /** @return list<RouteFileReference> */
    public function getReferences(): array
    {
        return $this->references;
    }

    private function staticCall(Node\Expr\StaticCall $call): void
    {
        // a static call that is not Route::group()
        if (! RouteGroupTracker::isOpener($call)) {
            return;
        }

        // Router::group(array $attributes, Closure|array|string $routes)
        $this->collect(RouteFileLoader::ROUTE_GROUP, Args::of($call->args)->at(1));
    }

    private function methodCall(Node\Expr\MethodCall $call): void
    {
        // a dynamic method name: $x->{$name}()
        if (! $call->name instanceof Node\Identifier) {
            return;
        }

        match ($call->name->toString()) {
            // $this->loadRoutesFrom($path) in a service provider
            'loadRoutesFrom' => $this->loadRoutesFrom($call),
            // Route::...->group($path)
            'group' => $this->fluentGroup($call),
            // Application::configure()->withRouting(web:, api:, commands:)
            'withRouting' => $this->withRouting($call),
            // ->withCommands([...]): command files or directories
            'withCommands' => $this->collect(RouteFileLoader::WITH_COMMANDS, Args::of($call->args)->at(0), allowsDirectory: true),
            default => null,
        };
    }

    private function loadRoutesFrom(Node\Expr\MethodCall $call): void
    {
        // only the provider's own loadRoutesFrom() counts, not `$other->loadRoutesFrom()`
        if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
            $this->collect(RouteFileLoader::LOAD_ROUTES_FROM, Args::of($call->args)->at(0));
        }
    }

    /** RouteRegistrar::group(Closure|array|string $callback) behind a `Route::` chain. */
    private function fluentGroup(Node\Expr\MethodCall $call): void
    {
        if (RouteGroupTracker::isOpener($call)) {
            $this->collect(RouteFileLoader::ROUTE_GROUP, Args::of($call->args)->at(0));
        }
    }

    private function withRouting(Node\Expr\MethodCall $call): void
    {
        foreach (Args::of($call->args)->all() as $arg) {
            // withRouting(...$args) cannot be mapped to parameters
            if ($arg->unpacked) {
                continue;
            }

            match (RoutingParameter::forArgument($arg->name, $arg->position)) {
                // web: Route::middleware('web')->group($path)
                RoutingParameter::WEB => $this->collect(RouteFileLoader::WITH_ROUTING, $arg, context: $this->routingContext(RoutingMiddlewareGroup::WEB, $call)),
                // api: Route::middleware('api')->prefix($apiPrefix)->group($path)
                RoutingParameter::API => $this->collect(RouteFileLoader::WITH_ROUTING, $arg, context: $this->routingContext(RoutingMiddlewareGroup::API, $call)),
                // commands: a console routes file or directory, never grouped
                RoutingParameter::COMMANDS => $this->collect(RouteFileLoader::WITH_ROUTING, $arg, allowsDirectory: true),
                // using, channels, pages, health, apiPrefix, then: not route files
                default => null,
            };
        }
    }

    /**
     * What `ApplicationBuilder::buildRoutingCallback()` wraps a routing file in:
     * `Route::middleware('web')->group()` or
     * `Route::middleware('api')->prefix($apiPrefix)->group()`.
     */
    private function routingContext(RoutingMiddlewareGroup $wrapper, Node\Expr\MethodCall $call): RouteGroupContext
    {
        // web files are not prefixed; api files take `apiPrefix`, null when not a literal
        $prefix = $wrapper === RoutingMiddlewareGroup::API ? $this->apiPrefix($call) : '';
        $segment = trim($prefix ?? '', '/');

        return new RouteGroupContext(
            // an empty prefix (`apiPrefix: ''`) adds no segment
            prefixSegments: $segment === '' ? [] : [$segment],
            namePrefix: '',
            controllerNode: null,
            middlewareNodes: [new Node\Scalar\String_($wrapper->value)],
            // literal not resolvable: recorded as unresolved, never guessed
            unresolved: $prefix === null ? [RouteGroupAttribute::PREFIX] : [],
        );
    }

    /** The literal `apiPrefix` of the call, its default when omitted, or null when not a literal. */
    private function apiPrefix(Node\Expr\MethodCall $call): ?string
    {
        foreach (Args::of($call->args)->all() as $arg) {
            if ($arg->unpacked) {
                continue;
            }

            // some other withRouting() argument
            if (RoutingParameter::forArgument($arg->name, $arg->position) !== RoutingParameter::API_PREFIX) {
                continue;
            }

            return Literal::string($arg->value);
        }

        // apiPrefix omitted: Laravel's default
        return RoutingMiddlewareGroup::DEFAULT_API_PREFIX;
    }

    private function collect(RouteFileLoader $loader, ?Arg $arg, bool $allowsDirectory = false, ?RouteGroupContext $context = null): void
    {
        // argument omitted
        if ($arg === null) {
            return;
        }

        $value = $arg->value;
        // an array of paths: one reference per element
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                $this->add($loader, $item->value, $allowsDirectory, $context);
            }

            return;
        }

        // a single path expression
        $this->add($loader, $value, $allowsDirectory, $context);
    }

    private function add(RouteFileLoader $loader, Node\Expr $value, bool $allowsDirectory, ?RouteGroupContext $context): void
    {
        // an inline closure holds routes itself; it is not a file to follow
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            return;
        }
        // `web: null` leaves the routing file unset
        if ($value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null') {
            return;
        }

        $this->references[] = new RouteFileReference($loader, $value, $value->getStartLine(), $allowsDirectory, $context ?? $this->groups->current());
    }
}
