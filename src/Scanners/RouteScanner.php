<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ChainLink;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Dto\RouteIssue;
use Lucasp\Loom\Dto\UnresolvedRouteEntry;
use Lucasp\Loom\Index\RouteKind;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Scanners\Route\ResourceExpander;
use Lucasp\Loom\Scanners\Visitors\RouteAttributeVisitor;
use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\Route\RouteGroupContext;
use Lucasp\Loom\Support\RegistrarSetter;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers HTTP routes declared via the `Route` facade under routes/ and in
 * the route files that providers, route files and bootstrap/app.php load.
 *
 * Route calls: `get/post/put/patch/delete/options/query` (one entry), `any`
 * (method `ANY`), `match([...])` (one entry per listed verb), `view`
 * (`GET`, kind `view`), `redirect` / `permanentRedirect` (`ANY`, kind
 * `redirect`) and `fallback` (`GET`, kind `fallback`). A chain may start with
 * registrar attributes (`Route::middleware('auth')->prefix('v1')->get(...)`).
 * The action is a tuple, an invokable `Ctrl::class` or one-element tuple
 * (`__invoke`), a legacy `'Ctrl@method'` string, or a closure (both
 * controller fields null). `->name()` appends to the name.
 *
 * Groups (array form and fluent form, nested outer to inner) contribute a
 * prefix (always a leading slash, root `/`), a name prefix concatenated as-is,
 * a default controller for bare method-name actions, middleware and
 * `withoutMiddleware` exclusions. Middleware is verbatim, with parameters kept
 * (`throttle:60,1`), `::class` resolved and `Class::class.':arg'` folded: a
 * repeated `middleware()` on a registrar chain keeps only the last call,
 * nested groups append inner after outer, calls on a route instance
 * accumulate; exact duplicates are removed, then exclusions are removed by
 * name as written. Aliases and groups are not expanded.
 *
 * The resource family is expanded by {@see ResourceExpander}. Routes whose URI,
 * resource name or verbs are not literal, `Route::<unknown>()` calls,
 * unreadable middleware or group attributes, route files that cannot be
 * followed and attribute routes are reported in `unresolved_routes`.
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

    /** The URI `Route::fallback()` registers before prefixes apply. */
    private const FALLBACK_URI = '{fallbackPlaceholder}';

    private AstWalker $walker;

    private ResourceExpander $resources;

    public function __construct(?AstWalker $walker = null, ?ScanScope $scope = null, ?RouteFileDiscovery $routeDiscovery = null)
    {
        $this->routeDiscovery = $routeDiscovery;
        $this->walker = $walker ?? new AstWalker;
        $this->scope = $scope;
        $this->resources = new ResourceExpander;
    }

    /**
     * @return array{routes: list<RouteEntry>, unresolved_routes: list<UnresolvedRouteEntry>}
     */
    public function scan(string $appRoot): array
    {
        $entries = [];
        $unresolved = [];
        $macros = $this->scope()->routeMacros();

        foreach ($this->routeFiles($appRoot) as $file) {
            // Fresh visitors per file: walk()===null bypasses beforeTraverse,
            // so reusing one would leak the previous file's entries. A file
            // loaded under several groups is read once per distinct group.
            $inherited = $this->routeDiscovery?->contexts($appRoot, $file->getPathname()) ?? [];
            $visitors = Arr::map($inherited === [] ? [RouteGroupContext::empty()] : $inherited, fn (RouteGroupContext $context): RouteChainVisitor => new RouteChainVisitor($context, $macros));
            if ($this->walker->walk($file->getPathname(), $visitors) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());

            foreach ($visitors as $visitor) {
                $translated = $this->translate($visitor->getEntries(), $relative);
                foreach ($translated['routes'] as $entry) {
                    $entries[] = $entry;
                }
                foreach ([...$visitor->getIssues(), ...$translated['issues']] as $issue) {
                    $unresolved[] = new UnresolvedRouteEntry($relative, $issue->line, $issue->expression, $issue->reason);
                }
            }
        }

        foreach ($this->discoveryProblems($appRoot) as $problem) {
            $unresolved[] = $problem;
        }
        foreach ($this->attributeRoutes($appRoot) as $problem) {
            $unresolved[] = $problem;
        }

        $entries = array_values(collect($entries)->sort(fn (RouteEntry $a, RouteEntry $b): int => [$a->file, $a->line, $a->method, $a->uri] <=> [$b->file, $b->line, $b->method, $b->uri])->all());

        // a file read under several contexts reports the same problem each time
        $unresolved = array_values(collect($unresolved)
            ->unique(fn (UnresolvedRouteEntry $e): string => $e->file.':'.$e->line.':'.$e->reason->value.':'.$e->expression)
            ->sort(fn (UnresolvedRouteEntry $a, UnresolvedRouteEntry $b): int => [$a->file, $a->line, $a->reason->value, $a->expression] <=> [$b->file, $b->line, $b->reason->value, $b->expression])
            ->all());

        return ['routes' => $entries, 'unresolved_routes' => $unresolved];
    }

    /**
     * Route-file loading calls that could not be followed or whose group
     * attributes were not literals, as reported by the discovery pass.
     *
     * @return list<UnresolvedRouteEntry>
     */
    private function discoveryProblems(string $appRoot): array
    {
        if ($this->routeDiscovery === null) {
            return [];
        }

        $out = [];
        foreach ($this->routeDiscovery->unresolved($appRoot) as $path) {
            $out[] = new UnresolvedRouteEntry($this->relativePath($appRoot, $path->file), $path->line, $path->expression, $path->problem->reason());
        }
        foreach ($this->routeDiscovery->unresolvedAttributes($appRoot) as $attribute) {
            $out[] = new UnresolvedRouteEntry($this->relativePath($appRoot, $attribute->file), $attribute->line, $attribute->loader->value.': group '.$attribute->attribute->value, UnresolvedRouteReason::DYNAMIC_GROUP_ATTRIBUTE);
        }

        return $out;
    }

    /**
     * Controllers that declare routes with attributes, which Loom does not read.
     *
     * @return list<UnresolvedRouteEntry>
     */
    private function attributeRoutes(string $appRoot): array
    {
        $out = [];
        foreach ($this->scanFiles($appRoot) as $file) {
            $source = @file_get_contents($file->getPathname());
            // cheap pre-filter: no attribute syntax, no attribute routes
            if ($source === false || ! Str::contains($source, '#[')) {
                continue;
            }

            $visitor = new RouteAttributeVisitor;
            if ($this->walker->walk($file->getPathname(), [$visitor]) === null) {
                continue;
            }
            $relative = $this->relativePath($appRoot, $file->getPathname());
            foreach ($visitor->getIssues() as $issue) {
                $out[] = new UnresolvedRouteEntry($relative, $issue->line, $issue->expression, $issue->reason);
            }
        }

        return $out;
    }

    /**
     * @param  list<RouteChainEntry>  $rawEntries
     * @return array{routes: list<RouteEntry>, issues: list<RouteIssue>}
     */
    private function translate(array $rawEntries, string $relativeFile): array
    {
        $routes = [];
        $issues = [];
        foreach ($rawEntries as $raw) {
            $expanded = $this->expand($raw, $relativeFile);
            foreach ($expanded['routes'] as $route) {
                $routes[] = $route;
            }
            foreach ([...$raw->issues, ...$expanded['issues']] as $issue) {
                $issues[] = $issue;
            }
        }

        return ['routes' => $routes, 'issues' => $issues];
    }

    /**
     * The URI of a route: its own, under the enclosing groups' prefixes, under
     * the prefix the chain sets before the call, under every `->prefix()`
     * written after it. Always leading-slash; root collapses to '/'.
     *
     * @param  list<string>  $segments  prefix segments, outermost first
     */
    private function uriOf(RouteChainEntry $raw, string $ownUri): string
    {
        // Route::prefix('b')->get(..)->prefix('a'): each later prefix sits further out
        $after = collect($raw->chain)->slice(1)
            ->filter(static fn (ChainLink $link): bool => $link->method === RegistrarSetter::PREFIX->value)
            ->map(static fn (ChainLink $link): string => Literal::string($link->args->valueAt(0)) ?? '')
            ->reverse()
            ->values()
            ->all();

        $segments = [];
        foreach ([...$after, $raw->leading->prefix ?? '', ...$raw->groupPrefix, $ownUri] as $part) {
            $trimmed = Str::trim($part, '/');
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }
        $joined = Arr::join($segments, '/');

        return $joined === '' ? '/' : '/'.$joined;
    }

    /**
     * The route name: the enclosing groups' prefix, the chain's own `name`,
     * then every `->name()` written after the call, concatenated as Laravel
     * appends them. Null when empty.
     */
    private function nameOf(RouteChainEntry $raw): ?string
    {
        $after = collect($raw->chain)->slice(1)
            ->filter(static fn (ChainLink $link): bool => $link->method === RegistrarSetter::NAME->value)
            ->map(static fn (ChainLink $link): string => Literal::string($link->args->valueAt(0)) ?? '')
            ->implode('');

        $full = $raw->groupNamePrefix.$raw->leading->name.$after;

        return $full === '' ? null : $full;
    }

    /**
     * Expand one raw chain into one entry per HTTP verb it declares.
     *
     * @return array{routes: list<RouteEntry>, issues: list<RouteIssue>}
     */
    private function expand(RouteChainEntry $raw, string $relativeFile): array
    {
        // resource, apiResource, singleton, apiSingleton and the array forms
        if ($raw->root->resourceKind() !== null) {
            return $this->resources->expand($raw, $relativeFile);
        }

        $args = $raw->rootArgs;

        $shape = match ($raw->root) {
            // Route::match([verbs], uri, action)
            RouterMethod::MATCH => ['uri' => 1, 'action' => 2, 'kind' => null],
            // Route::view(uri, view, ...) and the redirects: no controller of their own
            RouterMethod::VIEW => ['uri' => 0, 'action' => null, 'kind' => RouteKind::VIEW],
            RouterMethod::REDIRECT, RouterMethod::PERMANENT_REDIRECT => ['uri' => 0, 'action' => null, 'kind' => RouteKind::REDIRECT],
            // Route::fallback(action): the URI is the router's placeholder
            RouterMethod::FALLBACK => ['uri' => null, 'action' => 0, 'kind' => RouteKind::FALLBACK],
            // Route::get(uri, action) ... Route::any(uri, action)
            default => ['uri' => 0, 'action' => 1, 'kind' => null],
        };

        $verbs = match ($raw->root) {
            RouterMethod::MATCH => $this->verbList(Literal::strictStringList($args->valueAt(0))),
            RouterMethod::VIEW, RouterMethod::FALLBACK => ['GET'],
            RouterMethod::REDIRECT, RouterMethod::PERMANENT_REDIRECT => ['ANY'],
            default => [(string) $raw->root->verb()],
        };

        // match([...]) with verbs that are not a literal list
        if ($verbs === []) {
            return ['routes' => [], 'issues' => [new RouteIssue(UnresolvedRouteReason::DYNAMIC_VERBS, $raw->line, $raw->expression)]];
        }

        $ownUri = $shape['uri'] === null ? self::FALLBACK_URI : Literal::string($args->valueAt($shape['uri']));
        // a non-literal URI: no route can be placed
        if ($ownUri === null) {
            return ['routes' => [], 'issues' => [new RouteIssue(UnresolvedRouteReason::DYNAMIC_URI, $raw->line, $raw->expression)]];
        }

        $actionNode = $shape['action'] === null ? null : $args->valueAt($shape['action']);
        $action = Callables::routeAction($actionNode, $raw->controller);

        $routes = [];
        foreach ($verbs as $verb) {
            $routes[] = new RouteEntry(
                method: $verb,
                uri: $this->uriOf($raw, $ownUri),
                name: $this->nameOf($raw),
                controllerFqcn: $action['fqcn'],
                controllerMethod: $action['method'],
                middleware: $raw->middleware,
                file: $relativeFile,
                line: $raw->line,
                dispatches: [],
                endLine: Callables::closureEndLine($actionNode),
                kind: $shape['kind'],
            );
        }

        return ['routes' => $routes, 'issues' => []];
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
            ->map(static fn (string $name): string => Str::upper($name))
            ->filter(static fn (string $verb): bool => collect(RouterMethod::EMITTED_VERBS)->containsStrict($verb));

        return array_values($verbs->all());
    }
}
