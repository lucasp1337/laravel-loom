<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Index\ResourceAction;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\ScannerFilesystem;
use PhpParser\Node;

/**
 * Discovers HTTP routes declared via the `Route` facade under routes/.
 *
 * Slice 1: leaf verb routes only (get/post/.../any/match). Group prefixes,
 * middleware chains, and dispatch cross-links are out of scope.
 */
final class RouteScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    public function __construct(?AstWalker $walker = null)
    {
        $this->walker = $walker ?? new AstWalker;
    }

    /**
     * @return array{routes: list<RouteEntry>}
     */
    public function scan(string $appRoot): array
    {
        $routesDir = $appRoot.DIRECTORY_SEPARATOR.'routes';
        if (! is_dir($routesDir)) {
            return ['routes' => []];
        }

        $entries = [];

        foreach ($this->iteratePhpFiles($routesDir) as $file) {
            // Fresh visitor per file: walk()===null bypasses beforeTraverse,
            // so reusing one would leak the previous file's entries.
            $visitor = new RouteChainVisitor;
            if ($this->walker->walk($file->getPathname(), [$visitor]) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($this->translate($visitor->getEntries(), $relative) as $entry) {
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            fn (RouteEntry $a, RouteEntry $b): int => [$a->file, $a->line, $a->method, $a->uri] <=> [$b->file, $b->line, $b->method, $b->uri],
        );

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
        $joined = implode('/', $segments);

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

        if (in_array($raw->rootMethod, RouterMethod::resourceRoots(), true)) {
            return $this->expandResource($raw, $relativeFile);
        }

        $method = $raw->rootMethod === RouterMethod::ANY
            ? 'ANY'
            : RouterMethod::VERB_MAP[$raw->rootMethod];

        $ownUri = AstHelpers::scalarString($args[0] ?? null);
        if ($ownUri === null) {
            return [];
        }
        $uri = $this->groupUri($raw->groupPrefix, $ownUri);

        $action = $this->resolveAction($args[1] ?? null, $raw->groupController);

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

        $verbs = $this->verbList($args[0] ?? null);
        $ownUri = AstHelpers::scalarString($args[1] ?? null);
        if ($verbs === [] || $ownUri === null) {
            return [];
        }
        $uri = $this->groupUri($raw->groupPrefix, $ownUri);

        $action = $this->resolveAction($args[2] ?? null, $raw->groupController);

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

        $resourceName = AstHelpers::scalarString($args[0] ?? null);
        if ($resourceName === null) {
            return [];
        }
        $resourceName = trim($resourceName, '/');
        if ($resourceName === '') {
            return [];
        }

        // Controller may be unresolvable (variable, dynamic) — still expand.
        $controllerArg = $args[1] ?? null;
        $controllerFqcn = AstHelpers::classConstFqcn(
            $controllerArg instanceof Node\Arg ? $controllerArg->value : null,
        );

        $actions = $this->filterResourceActions($raw, $this->defaultResourceActions($raw->rootMethod));

        $param = $this->memberParameter($resourceName);
        $nameBase = str_replace('/', '.', $resourceName);

        $out = [];
        foreach ($actions as $action) {
            $ownUri = $resourceName.str_replace('{param}', '{'.$param.'}', $action->suffix());

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
        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            $method = $chain[$i]->method;
            if ($method === 'only') {
                $only = $this->stringArgList($chain[$i]->args);
            } elseif ($method === 'except') {
                $except = $this->stringArgList($chain[$i]->args);
            }
        }

        if ($only !== null) {
            $actions = array_values(array_filter(
                $actions,
                fn (ResourceAction $a): bool => in_array($a->value, $only, true),
            ));
        }
        if ($except !== []) {
            $actions = array_values(array_filter(
                $actions,
                fn (ResourceAction $a): bool => ! in_array($a->value, $except, true),
            ));
        }

        return $actions;
    }

    /**
     * Collect literal action names from `->only(...)` / `->except(...)` args,
     * accepting both an array argument and variadic string arguments.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @return list<string>
     */
    private function stringArgList(array $args): array
    {
        $names = [];
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }
            if ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    if ($item->value instanceof Node\Scalar\String_) {
                        $names[] = $item->value->value;
                    }
                }

                continue;
            }
            $string = AstHelpers::scalarString($arg->value);
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
    private function verbList(Node\Arg|Node\VariadicPlaceholder|null $arg): array
    {
        if (! $arg instanceof Node\Arg || ! $arg->value instanceof Node\Expr\Array_) {
            return [];
        }

        $verbs = [];
        foreach ($arg->value->items as $item) {
            if (! $item->value instanceof Node\Scalar\String_) {
                return [];
            }
            $verbs[] = strtoupper($item->value->value);
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
    private function resolveAction(Node\Arg|Node\VariadicPlaceholder|null $arg, ?string $groupController = null): array
    {
        if (! $arg instanceof Node\Arg) {
            return ['fqcn' => null, 'method' => null];
        }

        $value = $arg->value;

        // Closure / arrow function: no controller.
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            return ['fqcn' => null, 'method' => null];
        }

        // [Ctrl::class, 'method'] or [Ctrl::class].
        if ($value instanceof Node\Expr\Array_) {
            return $this->resolveArrayAction($value);
        }

        // Bare Ctrl::class -> invokable.
        $fqcn = AstHelpers::classConstFqcn($value);
        if ($fqcn !== null) {
            return ['fqcn' => $fqcn, 'method' => '__invoke'];
        }

        // 'Class@method' (legacy) or 'Class' (invokable string).
        $string = AstHelpers::scalarString($value);
        if ($string !== null) {
            return $this->resolveStringAction($string, $groupController);
        }

        // Variable, dynamic expression, etc. — never guess.
        return ['fqcn' => null, 'method' => null];
    }

    /**
     * @return array{fqcn: ?string, method: ?string}
     */
    private function resolveArrayAction(Node\Expr\Array_ $array): array
    {
        $tuple = AstHelpers::tupleCallable($array);
        if ($tuple !== null) {
            return ['fqcn' => $tuple['class'], 'method' => $tuple['method']];
        }

        // Single-element [Ctrl::class] -> invokable.
        if (count($array->items) === 1) {
            $fqcn = AstHelpers::classConstFqcn($array->items[0]->value);
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
        if (str_contains($action, '@')) {
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
            $label = AstHelpers::scalarString($chain[$i]->args[0] ?? null);
            if ($label !== null) {
                $name = $label;
            }
        }

        return $name;
    }
}
