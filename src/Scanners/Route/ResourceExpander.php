<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Route;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ResourceTarget;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Dto\RouteIssue;
use Lucasp\Loom\Index\ResourceAction;
use Lucasp\Loom\Index\ResourceKind;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Support\Ast\Route\ResourceCall;
use Lucasp\Loom\Support\Ast\Route\ResourceOptions;

/**
 * Expands a resource-family route call into the routes `ResourceRegistrar`
 * registers: action set, URIs (nested `a.b` names, wildcards), verbs
 * (`update` is `PUT` and `PATCH`), names (`names`, `as`, shallow) and
 * middleware (`middleware`, `middlewareFor`, exclusions).
 *
 * Attributes written before the call other than `as`, `middleware` and
 * `withoutMiddleware` (a `prefix`, say) are ignored: the registrar never
 * forwards them to a resource's routes.
 *
 * @internal
 */
final class ResourceExpander
{
    /**
     * @return array{routes: list<RouteEntry>, issues: list<RouteIssue>}
     */
    public function expand(RouteChainEntry $raw, string $relativeFile): array
    {
        $kind = $raw->root->resourceKind();
        if ($kind === null) {
            return ['routes' => [], 'issues' => []];
        }

        $call = new ResourceCall($raw);
        $issues = [];
        if ($call->dynamicTargets) {
            $issues[] = new RouteIssue(UnresolvedRouteReason::DYNAMIC_RESOURCE, $raw->line, $raw->expression);
        }
        if ($call->options->dynamic) {
            $issues[] = new RouteIssue(UnresolvedRouteReason::DYNAMIC_RESOURCE_OPTIONS, $raw->line, $raw->expression);
        }

        $routes = [];
        foreach ($call->targets as $target) {
            foreach ($this->routesOf($target, $kind, $call->options, $raw, $relativeFile) as $route) {
                $routes[] = $route;
            }
        }

        return ['routes' => $routes, 'issues' => $issues];
    }

    /** @return list<RouteEntry> */
    private function routesOf(ResourceTarget $target, ResourceKind $kind, ResourceOptions $options, RouteChainEntry $raw, string $file): array
    {
        // 'admin/photos': the leading segments become a URI prefix, the last is the resource
        $name = Str::afterLast($target->name, '/');
        $prefix = Str::contains($target->name, '/') ? explode('/', Str::beforeLast($target->name, '/')) : [];
        // the resource wildcard comes from the last dotted segment, as ResourceRegistrar::register()
        $leaf = Str::afterLast($name, '.');

        $routes = [];
        foreach ($this->actions($kind, $options) as $action) {
            // shallow(): member actions drop their parents
            $actionName = $options->shallow && $action->isShallowable() ? $leaf : $name;
            $uri = $this->uri($kind, $action, $actionName, $leaf, $options);
            $routeName = $raw->groupNamePrefix.$this->routeName($actionName, $action, $options);
            $middleware = $this->middleware($action, $options, $raw);

            foreach ($action->methods() as $verb) {
                $routes[] = new RouteEntry(
                    method: $verb,
                    uri: $this->join([...$raw->groupPrefix, ...$prefix, $uri]),
                    name: $routeName,
                    controllerFqcn: $target->controller,
                    controllerMethod: $action->value,
                    middleware: $middleware,
                    file: $file,
                    line: $raw->line,
                );
            }
        }

        return $routes;
    }

    /**
     * The registered actions: the kind's defaults, kept in default order when
     * listed by `only`, minus `except`.
     *
     * @return list<ResourceAction>
     */
    private function actions(ResourceKind $kind, ResourceOptions $options): array
    {
        $defaults = $kind->defaultActions($options->creatable, $options->destroyable);

        return array_values(Arr::where($defaults, static fn (ResourceAction $action): bool => ($options->only === null || collect($options->only)->containsStrict($action->value))
            && ! collect($options->except)->containsStrict($action->value)));
    }

    /** The action's URI under the resource name, wildcards included. */
    private function uri(ResourceKind $kind, ResourceAction $action, string $actionName, string $leaf, ResourceOptions $options): string
    {
        $segments = [$this->baseUri($actionName, $options)];

        // a plain resource's member actions address {wildcard}; a singleton has none
        if (! $kind->isSingleton() && $action->isMember()) {
            $segments[] = '{'.$this->wildcard($leaf, $options).'}';
        }

        // create / edit
        if ($action->segment() !== '') {
            $segments[] = $action->segment();
        }

        return Arr::join($segments, '/');
    }

    /** `ResourceRegistrar::getResourceUri()`: nested names get a wildcard per parent. */
    private function baseUri(string $resource, ResourceOptions $options): string
    {
        if (! Str::contains($resource, '.')) {
            return $resource;
        }

        $parts = explode('.', $resource);
        $uri = Arr::join(Arr::map($parts, fn (string $part): string => $part.'/{'.$this->wildcard($part, $options).'}'), '/');

        // the resource's own wildcard is added per action, so remove it here
        return Str::replace('/{'.$this->wildcard(Str::afterLast($resource, '.'), $options).'}', '', $uri);
    }

    /** `ResourceRegistrar::getResourceWildcard()`. */
    private function wildcard(string $segment, ResourceOptions $options): string
    {
        $mapped = is_array($options->parameters) ? ($options->parameters[$segment] ?? null) : null;

        // an explicit parameter name wins; otherwise the singular of the segment
        return Str::replace('-', '_', $mapped ?? Str::singular($segment));
    }

    /** `ResourceRegistrar::getResourceRouteName()`. */
    private function routeName(string $actionName, ResourceAction $action, ResourceOptions $options): string
    {
        // names(['index' => 'x.list']): used as written
        if (is_array($options->names) && isset($options->names[$action->value])) {
            return $options->names[$action->value];
        }

        // names('x') replaces the resource name
        $name = is_string($options->names) ? $options->names : $actionName;

        $prefix = $options->as !== null ? $options->as.'.' : '';

        return Str::trim($prefix.$name.'.'.$action->value, '.');
    }

    /**
     * Group middleware, then the action's own, minus every exclusion.
     *
     * @return list<string>
     */
    private function middleware(ResourceAction $action, ResourceOptions $options, RouteChainEntry $raw): array
    {
        $own = $options->middlewareFor[$action->value] ?? $options->middleware ?? [];
        $excluded = [...$raw->groupExcluded, ...$options->excluded, ...($options->excludedFor[$action->value] ?? [])];

        return array_values(collect([...$raw->groupMiddleware, ...$own])
            ->unique()
            ->reject(static fn (string $name): bool => collect($excluded)->containsStrict($name))
            ->all());
    }

    /**
     * Join prefix segments and the resource URI; always a leading slash.
     *
     * @param  list<string>  $segments
     */
    private function join(array $segments): string
    {
        $parts = collect($segments)->map(static fn (string $segment): string => Str::trim($segment, '/'))->filter(static fn (string $segment): bool => $segment !== '');

        return '/'.$parts->implode('/');
    }
}
