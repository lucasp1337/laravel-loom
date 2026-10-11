<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Index\ResourceAction;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\Route\RouteGroupContext;
use Lucasp\Loom\Support\ResourceFilter;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers HTTP routes declared via the `Route` facade under routes/ and in
 * the route files that providers and bootstrap/app.php load.
 *
 * Roots: `Route::get/post/put/patch/delete/options` (one entry), `any` (method
 * `ANY`) and `match([...])` (one entry per listed verb). The action is a tuple,
 * an invokable `Ctrl::class` or one-element tuple (`__invoke`), a legacy
 * `'Ctrl@method'` string, or a closure (both controller fields null). `->name()`
 * on the chain sets the name.
 *
 * Groups (array form and fluent form, nested outer to inner) contribute a
 * prefix (always a leading slash, root `/`), a name prefix concatenated as-is,
 * a default controller for bare method-name actions (an action that names a
 * class keeps its own), and middleware. Middleware is verbatim, with
 * parameters kept (`throttle:60,1`) and `::class` resolved: a repeated
 * `middleware()` on a group registrar chain keeps only the last call, nested
 * groups append inner after outer, and calls on a route instance accumulate;
 * exact duplicates are removed. Aliases and groups are not expanded.
 *
 * `Route::resource` expands to seven routes and `apiResource` to five
 * (no `create`/`edit`), with `{singular}` member parameters, `update` as a
 * single `PUT`, enclosing group context applied, and `->only()`/`->except()`
 * honoured. `names()`, `parameters()`, `scoped()` and `shallow()` are not
 * applied.
 *
 * Files loaded under a group inherit its context: see
 * {@see RouteFileLoadVisitor}. A file loaded from two groups is read once per
 * context, so its routes appear under both.
 *
 * @internal
 */
final class RouteScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null, ?ScanScope $scope = null, ?RouteFileDiscovery $routeDiscovery = null)
    {
        $this->routeDiscovery = $routeDiscovery;
        $this->walker = $walker ?? new AstWalker;
        $this->scope = $scope;
    }

    /**
     * @return array{routes: list<RouteEntry>}
     */
    public function scan(string $appRoot): array
    {
        $entries = [];

        foreach ($this->routeFiles($appRoot) as $file) {
            // Fresh visitors per file: walk()===null bypasses beforeTraverse,
            // so reusing one would leak the previous file's entries. A file
            // loaded under several groups is read once per distinct group.
            $inherited = $this->routeDiscovery?->contexts($appRoot, $file->getPathname()) ?? [];
            $visitors = Arr::map($inherited === [] ? [RouteGroupContext::empty()] : $inherited, fn (RouteGroupContext $context): RouteChainVisitor => new RouteChainVisitor($context));
            if ($this->walker->walk($file->getPathname(), $visitors) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($visitors as $visitor) {
                foreach ($this->translate($visitor->getEntries(), $relative) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        $entries = array_values(collect($entries)->sort(fn (RouteEntry $a, RouteEntry $b): int => [$a->file, $a->line, $a->method, $a->uri] <=> [$b->file, $b->line, $b->method, $b->uri])->all());

        return ['routes' => $entries];
    }

    /**
     * @param  list<RouteChainEntry>  $rawEntries
     * @return list<RouteEntry>
     */
    private function translate(array $rawEntries, string $relativeFile): array
    {
        $out = [];
        foreach ($rawEntries as $raw) {
            $name = $this->groupName($raw->groupNamePrefix, $this->resolveName($raw));

            foreach ($this->expand($raw, $relativeFile, $name) as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Apply the enclosing group's cumulative prefix segments to a route's own
     * uri. Always leading-slash; root collapses to '/'. Ungrouped routes (empty
     * prefix) stay byte-identical to slice 1.
     *
     * @param  list<string>  $groupPrefix
     */
    private function groupUri(array $groupPrefix, string $ownUri): string
    {
        $segments = [];
        foreach ($groupPrefix as $p) {
            $t = trim($p, '/');
            if ($t !== '') {
                $segments[] = $t;
            }
        }
        $own = trim($ownUri, '/');
        if ($own !== '') {
            $segments[] = $own;
        }
        $joined = Arr::join($segments, '/');

        return $joined === '' ? '/' : '/'.$joined;
    }

    /**
     * Concatenate the group name prefix with the leaf name (no separator). Empty
     * result becomes null, so ungrouped unnamed routes stay null as in slice 1.
     */
    private function groupName(string $groupNamePrefix, ?string $leafName): ?string
    {
        $full = $groupNamePrefix.($leafName ?? '');

        return $full === '' ? null : $full;
    }

    /**
     * Expand one raw chain into one entry per HTTP verb it declares.
     *
     * @return list<RouteEntry>
     */
    private function expand(RouteChainEntry $raw, string $relativeFile, ?string $name): array
    {
        $args = $raw->rootArgs;

        if ($raw->rootMethod === RouterMethod::MATCH) {
            return $this->expandMatch($raw, $relativeFile, $name);
        }

        if (collect(RouterMethod::resourceRoots())->containsStrict($raw->rootMethod)) {
            return $this->expandResource($raw, $relativeFile);
        }

        $method = $raw->rootMethod === RouterMethod::ANY
            ? 'ANY'
            : RouterMethod::VERB_MAP[$raw->rootMethod];

        $ownUri = Literal::string($args->valueAt(0));
        if ($ownUri === null) {
            return [];
        }
        $uri = $this->groupUri($raw->groupPrefix, $ownUri);

        $action = Callables::routeAction($args->valueAt(1), $raw->groupController);

        return [new RouteEntry(
            method: $method,
            uri: $uri,
            name: $name,
            controllerFqcn: $action['fqcn'],
            controllerMethod: $action['method'],
            middleware: $raw->middleware,
            file: $relativeFile,
            line: $raw->line,
            dispatches: [],
            endLine: Callables::closureEndLine($args->valueAt(1)),
        )];
    }

    /**
     * `Route::match([verbs], uri, action)` — one entry per uppercased verb,
     * all sharing uri/action/name.
     *
     * @return list<RouteEntry>
     */
    private function expandMatch(RouteChainEntry $raw, string $relativeFile, ?string $name): array
    {
        $args = $raw->rootArgs;

        $verbs = $this->verbList(Literal::strictStringList($args->valueAt(0)));
        $ownUri = Literal::string($args->valueAt(1));
        if ($verbs === [] || $ownUri === null) {
            return [];
        }
        $uri = $this->groupUri($raw->groupPrefix, $ownUri);

        $action = Callables::routeAction($args->valueAt(2), $raw->groupController);

        $out = [];
        foreach ($verbs as $verb) {
            $out[] = new RouteEntry(
                method: $verb,
                uri: $uri,
                name: $name,
                controllerFqcn: $action['fqcn'],
                controllerMethod: $action['method'],
                middleware: $raw->middleware,
                file: $relativeFile,
                line: $raw->line,
                dispatches: [],
                endLine: Callables::closureEndLine($args->valueAt(2)),
            );
        }

        return $out;
    }

    /**
     * `Route::resource(name, Controller)` / `apiResource(...)` — one entry per
     * registered action. The action set is filtered by chain `->only(...)` /
     * `->except(...)`; names and uris use Laravel defaults (custom
     * `->names()`/`->parameters()` overrides are out of scope).
     *
     * @return list<RouteEntry>
     */
    private function expandResource(RouteChainEntry $raw, string $relativeFile): array
    {
        $args = $raw->rootArgs;

        $resourceName = Literal::string($args->valueAt(0));
        if ($resourceName === null) {
            return [];
        }
        $resourceName = trim($resourceName, '/');
        if ($resourceName === '') {
            return [];
        }

        // Controller may be unresolvable (variable, dynamic) — still expand.
        $controllerFqcn = ClassRef::fromClassConstant($args->valueAt(1));

        $actions = $this->filterResourceActions($raw, $this->defaultResourceActions($raw->rootMethod));

        $param = $this->memberParameter($resourceName);
        $nameBase = Str::replace('/', '.', $resourceName);

        $out = [];
        foreach ($actions as $action) {
            $ownUri = $resourceName.Str::replace('{param}', '{'.$param.'}', $action->suffix());

            $out[] = new RouteEntry(
                method: $action->method(),
                uri: $this->groupUri($raw->groupPrefix, $ownUri),
                name: $raw->groupNamePrefix.$nameBase.'.'.$action->value,
                controllerFqcn: $controllerFqcn,
                controllerMethod: $action->value,
                middleware: $raw->middleware,
                file: $relativeFile,
                line: $raw->line,
                dispatches: [],
            );
        }

        return $out;
    }

    /**
     * The default action set for a resource root, before only/except filtering.
     *
     * @return list<ResourceAction>
     */
    private function defaultResourceActions(string $rootMethod): array
    {
        return $rootMethod === RouterMethod::API_RESOURCE
            ? ResourceAction::apiSet()
            : ResourceAction::fullSet();
    }

    /**
     * Apply chain `->only([...])` then `->except([...])` filters, preserving the
     * canonical action order. Unrecognised option methods are ignored.
     *
     * @param  list<ResourceAction>  $actions
     * @return list<ResourceAction>
     */
    private function filterResourceActions(RouteChainEntry $raw, array $actions): array
    {
        $only = null;
        $except = [];

        $chain = $raw->chain;
        // Index 0 is the root call; modifiers start at index 1.
        foreach (collect($chain)->slice(1)->all() as $link) {
            match (ResourceFilter::tryFrom($link->method)) {
                // ->only(['index', 'show'])
                ResourceFilter::ONLY => $only = $link->args->stringValues(),
                // ->except(['create', 'edit'])
                ResourceFilter::EXCEPT => $except = $link->args->stringValues(),
                // any other chain modifier does not narrow the action set
                null => null,
            };
        }

        if ($only !== null) {
            $actions = array_values(Arr::where($actions, fn (ResourceAction $a): bool => collect($only)->containsStrict($a->value)));
        }
        if ($except !== []) {
            $actions = array_values(Arr::where($actions, fn (ResourceAction $a): bool => ! collect($except)->containsStrict($a->value)));
        }

        return $actions;
    }

    /**
     * The member route parameter: the singular of the resource name's last
     * segment, via Laravel's own inflector for faithful pluralisation rules.
     */
    private function memberParameter(string $resourceName): string
    {
        $segments = explode('/', $resourceName);
        $last = $segments[count($segments) - 1];

        return Str::singular($last);
    }

    /**
     * Uppercase the HTTP verbs of a `match` call's literal verb array, keeping
     * those the router emits. Empty when the array is not all string literals.
     *
     * @param  list<string>|null  $names
     * @return list<string>
     */
    private function verbList(?array $names): array
    {
        $verbs = collect($names ?? [])
            ->map(static fn (string $name): string => strtoupper($name))
            ->filter(static fn (string $verb): bool => collect(RouterMethod::EMITTED_VERBS)->containsStrict($verb));

        return array_values($verbs->all());
    }

    /**
     * Scan chain links for a `->name(<string>)` call. Last-wins; null when
     * absent or unresolvable.
     */
    private function resolveName(RouteChainEntry $raw): ?string
    {
        $name = null;

        // Index 0 is the root call; modifiers start at index 1.
        $chain = $raw->chain;
        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            if ($chain[$i]->method !== 'name') {
                continue;
            }
            $label = Literal::string($chain[$i]->args->valueAt(0));
            if ($label !== null) {
                $name = $label;
            }
        }

        return $name;
    }
}
