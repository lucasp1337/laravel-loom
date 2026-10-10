<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Dto\RouteGroupContext;
use Lucasp\Loom\Index\ResourceAction;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ResourceFilter;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;
use PhpParser\Node;

/**
 * Discovers HTTP routes declared via the `Route` facade under routes/.
 *
 * Slice 1: leaf verb routes only (get/post/.../any/match). Group prefixes,
 * middleware chains, and dispatch cross-links are out of scope.
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

        $action = $this->resolveAction($args->valueAt(1), $raw->groupController);

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
            endLine: $this->closureEndLine($args->valueAt(1)),
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

        $verbs = $this->verbList($args->valueAt(0));
        $ownUri = Literal::string($args->valueAt(1));
        if ($verbs === [] || $ownUri === null) {
            return [];
        }
        $uri = $this->groupUri($raw->groupPrefix, $ownUri);

        $action = $this->resolveAction($args->valueAt(2), $raw->groupController);

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
                endLine: $this->closureEndLine($args->valueAt(2)),
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
                ResourceFilter::ONLY => $only = $this->stringArgList($link->args),
                // ->except(['create', 'edit'])
                ResourceFilter::EXCEPT => $except = $this->stringArgList($link->args),
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
     * Collect literal action names from `->only(...)` / `->except(...)` args,
     * accepting both an array argument and variadic string arguments.
     *
     * @return list<string>
     */
    private function stringArgList(Args $args): array
    {
        $names = [];
        foreach ($args->values() as $value) {
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item->value instanceof Node\Scalar\String_) {
                        $names[] = $item->value->value;
                    }
                }

                continue;
            }
            $string = Literal::string($value);
            if ($string !== null) {
                $names[] = $string;
            }
        }

        return $names;
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
     * Resolve the verb array of a `match` call to uppercase HTTP verbs. Returns
     * empty when not a literal array of strings.
     *
     * @return list<string>
     */
    private function verbList(?Node\Expr $value): array
    {
        if (! $value instanceof Node\Expr\Array_) {
            return [];
        }

        $verbs = [];
        foreach ($value->items as $item) {
            if (! $item->value instanceof Node\Scalar\String_) {
                return [];
            }
            $verb = strtoupper($item->value->value);
            if (collect(RouterMethod::EMITTED_VERBS)->containsStrict($verb)) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    /**
     * Resolve a route action node to a controller FQCN + method. A bare
     * method-name string binds to $groupController (the enclosing group's
     * default controller) when one is present.
     *
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveAction(?Node\Expr $value, ?string $groupController = null): array
    {
        // argument omitted
        if ($value === null) {
            return ['fqcn' => null, 'method' => null];
        }

        // Closure / arrow function: no controller.
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            return ['fqcn' => null, 'method' => null];
        }

        // [Ctrl::class, 'method'] or [Ctrl::class].
        if ($value instanceof Node\Expr\Array_) {
            return $this->resolveArrayAction($value);
        }

        // Bare Ctrl::class -> invokable.
        $fqcn = ClassRef::fromClassConstant($value);
        if ($fqcn !== null) {
            return ['fqcn' => $fqcn, 'method' => '__invoke'];
        }

        // 'Class@method' (legacy) or 'Class' (invokable string).
        $string = Literal::string($value);
        if ($string !== null) {
            return $this->resolveStringAction($string, $groupController);
        }

        // Variable, dynamic expression, etc. — never guess.
        return ['fqcn' => null, 'method' => null];
    }

    private function closureEndLine(?Node\Expr $value): ?int
    {
        return $value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction
            ? $value->getEndLine()
            : null;
    }

    /**
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveArrayAction(Node\Expr\Array_ $array): array
    {
        $tuple = Callables::tuple($array);
        if ($tuple !== null) {
            return ['fqcn' => $tuple['class'], 'method' => $tuple['method']];
        }

        // Single-element [Ctrl::class] -> invokable.
        if (count($array->items) === 1) {
            $fqcn = ClassRef::fromClassConstant($array->items[0]->value);
            if ($fqcn !== null) {
                return ['fqcn' => $fqcn, 'method' => '__invoke'];
            }
        }

        return ['fqcn' => null, 'method' => null];
    }

    /**
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveStringAction(string $action, ?string $groupController = null): array
    {
        if (Str::contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return ['fqcn' => ltrim($class, '\\'), 'method' => $method];
        }

        // Bare method name under a group controller -> Controller::method.
        if ($groupController !== null) {
            return ['fqcn' => ltrim($groupController, '\\'), 'method' => $action];
        }

        // No '@' and no group controller -> invokable controller string.
        return ['fqcn' => ltrim($action, '\\'), 'method' => '__invoke'];
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
