<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ChainLink;
use Lucasp\Loom\Dto\RouteChainEntry;
use Lucasp\Loom\Dto\RouteIssue;
use Lucasp\Loom\Dto\RouteLeading;
use Lucasp\Loom\Index\RouterMethod;
use Lucasp\Loom\Index\UnresolvedRouteReason;
use Lucasp\Loom\Support\Ast\CallChain;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\Route\RouteGroupAttributes;
use Lucasp\Loom\Support\Ast\Route\RouteGroupContext;
use Lucasp\Loom\Support\Ast\Route\RouteGroupTracker;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\RegistrarSetter;
use Lucasp\Loom\Support\RouteGroupAttribute;
use Lucasp\Loom\Support\RouterNonRouteCall;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Captures `Route` facade route chains: a route-declaring call (verb, `match`,
 * resource family, `view`, `redirect`, `fallback`, or a configured macro),
 * optionally preceded by registrar attributes
 * (`Route::middleware('auth')->prefix('v1')->get(...)`) and followed by route
 * modifiers (`->name()`, `->middleware()`).
 *
 * Anything it cannot read is reported through {@see getIssues()}: a
 * `Route::<unknown>()` call, and group attributes that are not literals.
 *
 * @internal
 */
final class RouteChainVisitor extends CollectingVisitor
{
    /** Longest expression kept for a report. */
    private const EXPRESSION_LIMIT = 80;

    /** @var array<int, Node> */
    private array $parentStack = [];

    private RouteGroupTracker $groups;

    private PrettyPrinter $printer;

    /** @var list<RouteChainEntry> */
    private array $entries = [];

    /** @var list<RouteIssue> */
    private array $issues = [];

    /**
     * @param  ?RouteGroupContext  $inherited  attributes of the loading call, applied around every route of the file
     * @param  array<string, RouterMethod>  $macros  configured macro names and the router method each behaves like
     */
    public function __construct(?RouteGroupContext $inherited = null, private readonly array $macros = [])
    {
        $this->groups = new RouteGroupTracker($inherited);
        $this->printer = new PrettyPrinter;
    }

    protected function reset(): void
    {
        $this->parentStack = [];
        $this->groups->reset();
        $this->entries = [];
        $this->issues = [];
    }

    public function enterNode(Node $node): null
    {
        $this->parentStack[] = $node;

        $this->groups->enter($node);

        foreach ($this->groups->drainOpened() as $opened) {
            $this->reportGroup($opened);
        }

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

        $callChain = CallChain::from($node);
        // a dynamic method or class name, so the chain cannot be read
        if ($callChain === null) {
            return null;
        }

        $links = $callChain->links();
        $receiver = $links[0]->receiver();
        // a verb call on something other than the Route facade (e.g. a collection's ->get())
        if ($receiver === null || ! RouteGroupTracker::isRouteReceiver($receiver)) {
            return null;
        }

        $located = $this->locate($links);
        // a group, a binding helper or a setter chain with no route call
        if ($located === null) {
            return null;
        }

        [$index, $method] = $located;

        // not a router method and not configured: a macro or package helper
        if ($method === null) {
            $this->issues[] = new RouteIssue(UnresolvedRouteReason::UNKNOWN_ROUTE_CALL, $links[0]->line(), $this->render($links[0]->node()));

            return null;
        }

        $this->entries[] = $this->entry($links, $index, $method);

        return null;
    }

    /** @return list<RouteChainEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /** @return list<RouteIssue> */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * The route call of a chain: its index and router method, or a null method
     * for an unrecognised `Route::<name>()`. Null when the chain declares no
     * route.
     *
     * @param  non-empty-list<CallSite>  $links
     * @return array{0: int, 1: ?RouterMethod}|null
     */
    private function locate(array $links): ?array
    {
        foreach ($links as $index => $link) {
            $name = (string) $link->name();
            $method = $this->macros[$name] ?? RouterMethod::tryFrom($name);

            // a route call: at the root any, after attributes only those the registrar forwards
            if ($method !== null && ($index === 0 || $method->followsAttributes())) {
                return [$index, $method];
            }

            // Route::middleware('auth')->...: an attribute setter, keep looking
            if (RegistrarSetter::tryFrom($name) !== null) {
                continue;
            }

            // Route::group(), Route::model(), ...: not a route call
            if ($index > 0 || RouterNonRouteCall::contains($name)) {
                return null;
            }

            // Route::someMacro(): no router method of that name
            return [0, null];
        }

        // setters only
        return null;
    }

    /** @param  non-empty-list<CallSite>  $links */
    private function entry(array $links, int $index, RouterMethod $method): RouteChainEntry
    {
        $chain = [];
        foreach (collect($links)->slice($index)->all() as $link) {
            $chain[] = ChainLink::fromSite($link);
        }

        $issues = [];
        $leading = $this->leading(collect($links)->slice(0, $index)->values()->all(), $issues);

        $context = $this->groups->current();

        // Resolved here, not at push time: NameResolver only rewrites a name
        // node once traversal descends into it, which is after the group's
        // enterNode but before this leaf is reached.
        $groupMiddleware = $context->middleware();
        $groupExcluded = $context->excluded();

        $postMiddleware = [];
        $postExcluded = [];
        foreach (collect($chain)->slice(1)->all() as $link) {
            $nodes = $link->args->values();
            match (RegistrarSetter::tryFrom($link->method)) {
                // ->middleware('a', 'b') on the route accumulates
                RegistrarSetter::MIDDLEWARE => $postMiddleware = [...$postMiddleware, ...$this->middlewareNames($nodes, $issues)],
                // ->withoutMiddleware('a') on the route accumulates
                RegistrarSetter::WITHOUT_MIDDLEWARE => $postExcluded = [...$postExcluded, ...$this->middlewareNames($nodes, $issues)],
                // name, prefix and the rest are read by the scanner
                default => null,
            };
        }

        $excluded = [...$groupExcluded, ...$leading->excluded, ...$postExcluded];
        $middleware = collect([...$groupMiddleware, ...($leading->middleware ?? []), ...$postMiddleware])
            ->unique()
            ->reject(static fn (string $name): bool => collect($excluded)->containsStrict($name))
            ->values()
            ->all();

        return new RouteChainEntry(
            root: $method,
            rootArgs: $links[$index]->args(),
            chain: $chain,
            line: $links[0]->line(),
            expression: $this->render($links[$index]->node()),
            groupPrefix: $context->prefixSegments,
            groupNamePrefix: $context->namePrefix,
            controller: $leading->controller ?? $context->controller(),
            groupMiddleware: $groupMiddleware,
            groupExcluded: $groupExcluded,
            leading: $leading,
            middleware: $middleware,
            issues: $issues,
        );
    }

    /**
     * Read the registrar setters written before the route call. Each repeats
     * as on `RouteRegistrar::attribute()`: a prefix, name, controller or
     * middleware replaces the earlier value, exclusions accumulate.
     *
     * @param  list<CallSite>  $setters
     * @param  list<RouteIssue>  $issues
     */
    private function leading(array $setters, array &$issues): RouteLeading
    {
        $prefix = null;
        $name = '';
        $controller = null;
        $middleware = null;
        $excluded = [];

        foreach ($setters as $setter) {
            $args = $setter->args();
            $first = $args->valueAt(0);

            match (RegistrarSetter::tryFrom((string) $setter->name())) {
                // ->prefix('v1'): slashes trimmed, empty means none
                RegistrarSetter::PREFIX => $prefix = $this->literalAttribute($first, $setter, $issues, static fn (string $value): ?string => Str::trim($value, '/') === '' ? null : Str::trim($value, '/')),
                // ->name('x.') / ->as('x.'): kept as written
                RegistrarSetter::NAME, RegistrarSetter::AS => $name = $this->literalAttribute($first, $setter, $issues, static fn (string $value): string => $value) ?? '',
                // ->controller(Ctrl::class)
                RegistrarSetter::CONTROLLER => $controller = $this->controllerAttribute($first, $setter, $issues),
                // ->middleware(...) replaces the earlier call
                RegistrarSetter::MIDDLEWARE => $middleware = $this->middlewareNames($args->values(), $issues),
                // ->withoutMiddleware(...) accumulates
                RegistrarSetter::WITHOUT_MIDDLEWARE => $excluded = [...$excluded, ...$this->middlewareNames($args->values(), $issues)],
                // domain, where, can, scope bindings, ... do not change what Loom reports
                default => null,
            };
        }

        return new RouteLeading($prefix, $name, $controller, $middleware, $excluded);
    }

    /**
     * @param  list<RouteIssue>  $issues
     * @param  callable(string): (string|null)  $normalise
     */
    private function literalAttribute(?Node\Expr $node, CallSite $setter, array &$issues, callable $normalise): ?string
    {
        $literal = Literal::string($node);
        if ($literal === null) {
            $issues[] = new RouteIssue(UnresolvedRouteReason::DYNAMIC_GROUP_ATTRIBUTE, $setter->line(), $this->render($setter->node()));

            return null;
        }

        return $normalise($literal);
    }

    /** @param  list<RouteIssue>  $issues */
    private function controllerAttribute(?Node\Expr $node, CallSite $setter, array &$issues): ?string
    {
        $fqcn = ClassRef::fromClassConstant($node);
        if ($fqcn === null) {
            $issues[] = new RouteIssue(UnresolvedRouteReason::DYNAMIC_GROUP_ATTRIBUTE, $setter->line(), $this->render($setter->node()));
        }

        return $fqcn;
    }

    /**
     * @param  list<Node\Expr>  $nodes
     * @param  list<RouteIssue>  $issues
     * @return list<string>
     */
    private function middlewareNames(array $nodes, array &$issues): array
    {
        foreach (ValueLists::unreadableMiddleware($nodes) as $unreadable) {
            $issues[] = new RouteIssue(UnresolvedRouteReason::DYNAMIC_MIDDLEWARE, $unreadable->getStartLine(), $this->render($unreadable));
        }

        return ValueLists::middleware($nodes);
    }

    /** Report the group attributes of a freshly opened group that are not literals. */
    private function reportGroup(RouteGroupAttributes $opened): void
    {
        foreach ($opened->unresolved as $attribute) {
            $node = $opened->unresolvedNodes[$attribute->value] ?? null;
            $reason = $attribute === RouteGroupAttribute::MIDDLEWARE || $attribute === RouteGroupAttribute::WITHOUT_MIDDLEWARE
                ? UnresolvedRouteReason::DYNAMIC_MIDDLEWARE
                : UnresolvedRouteReason::DYNAMIC_GROUP_ATTRIBUTE;

            $this->issues[] = new RouteIssue(
                $reason,
                $node?->getStartLine() ?? $opened->line,
                $node === null ? 'group '.$attribute->value : $this->render($node),
            );
        }
    }

    private function render(Node $node): string
    {
        try {
            $rendered = $node instanceof Node\Expr ? $this->printer->prettyPrintExpr($node) : $this->printer->prettyPrint([$node]);
        } catch (\Throwable) {
            $rendered = '(unprintable)';
        }

        $rendered = Str::squish($rendered);

        return Str::length($rendered) > self::EXPRESSION_LIMIT ? Str::substr($rendered, 0, self::EXPRESSION_LIMIT - 3).'...' : $rendered;
    }

    private function currentParent(): ?Node
    {
        if ($this->parentStack === []) {
            return null;
        }

        return $this->parentStack[count($this->parentStack) - 1];
    }
}
