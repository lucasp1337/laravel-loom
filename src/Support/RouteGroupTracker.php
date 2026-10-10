<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\RouteGroupAttributes;
use Lucasp\Loom\Dto\RouteGroupContext;
use PhpParser\Node;

/**
 * Follows the `Route::...->group(...)` calls enclosing the node a visitor is
 * on, so route chains and route-file loading calls see the same cumulative
 * prefix, name prefix, controller and middleware. A visitor forwards its
 * `enterNode` / `leaveNode` to {@see enter()} / {@see leave()}.
 *
 * @internal
 */
final class RouteGroupTracker
{
    /**
     * Cumulative frames, one per open group. The top is the context an inner
     * node inherits; the base sits below all of them.
     *
     * @var list<RouteGroupContext>
     */
    private array $stack = [];

    private RouteGroupContext $base;

    public function __construct(?RouteGroupContext $base = null)
    {
        $this->base = $base ?? RouteGroupContext::empty();
    }

    public function reset(): void
    {
        $this->stack = [];
    }

    /** PhpParser enters a group call before descending into its closure, so pushing here keeps the top correct for inner nodes. */
    public function enter(Node $node): void
    {
        if (self::isOpener($node)) {
            $this->stack[] = $this->current()->merge($this->attributes($node));
        }
    }

    /** Same predicate as {@see enter()} so the stack balances exactly. */
    public function leave(Node $node): void
    {
        if (self::isOpener($node)) {
            array_pop($this->stack);
        }
    }

    public function current(): RouteGroupContext
    {
        return $this->stack === [] ? $this->base : $this->stack[count($this->stack) - 1];
    }

    /**
     * A "group opener" is a `group` call rooting at the `Route` facade, in
     * either the array-config (`Route::group([...], fn)`) or fluent
     * (`Route::prefix('x')->...->group(fn)`) form.
     */
    public static function isOpener(Node $node): bool
    {
        // Route::group([...], $routes)
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'group'
                && $node->class instanceof Node\Name
                && self::isRouteReceiver($node->class);
        }

        // Route::prefix('x')->...->group($routes)
        if ($node instanceof Node\Expr\MethodCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'group'
                && self::fluentRootIsRoute($node->var);
        }

        return false;
    }

    public static function isRouteReceiver(Node $receiver): bool
    {
        // a variable or expression receiver is not the facade
        if (! $receiver instanceof Node\Name) {
            return false;
        }

        $resolved = $receiver->getAttribute('resolvedName');
        // NameResolver saw the `use` import: compare the full facade FQCN
        if ($resolved instanceof Node\Name) {
            return $resolved->toString() === Facades::ROUTE->value;
        }

        // Fallback when NameResolver didn't attach a resolved name.
        return Facades::ROUTE->matches($receiver->toString());
    }

    /** Walk a fluent `->var` chain to its root and check it is the Route facade. */
    private static function fluentRootIsRoute(Node\Expr $expr): bool
    {
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && self::isRouteReceiver($expr->class);
    }

    /** Parse one group call's OWN attributes (prefix / name / controller / middleware). */
    private function attributes(Node $node): RouteGroupAttributes
    {
        // Route::group([...], $routes): attributes are the array config
        if ($node instanceof Node\Expr\StaticCall) {
            return $this->staticAttributes($node);
        }

        // Route::prefix('x')->...->group($routes): attributes are the fluent setters
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->fluentAttributes($node->var);
        }

        return (new RouteGroupAttributesBuilder)->build();
    }

    private function staticAttributes(Node\Expr\StaticCall $call): RouteGroupAttributes
    {
        $builder = new RouteGroupAttributesBuilder;
        $config = $call->args[0] ?? null;

        // a variable or call as config: nothing in it can be read
        if (! $config instanceof Node\Arg || ! $config->value instanceof Node\Expr\Array_) {
            $builder->markAttributesUnresolved();

            return $builder->build();
        }

        foreach ($config->value->items as $item) {
            $attribute = RouteGroupAttribute::fromConfigKey(AstHelpers::scalarString($item->key));

            // a key Loom does not apply (domain, where, ...) or a spread
            if ($attribute === null) {
                continue;
            }

            // array config: a repeated key replaces the earlier value
            $builder->set($attribute, [$item->value], accumulate: false);
        }

        return $builder->build();
    }

    /**
     * Read the setters of a fluent group's `->var` chain in source order, e.g.
     * `Route::prefix('x')->name('y.')->controller(C::class)`.
     */
    private function fluentAttributes(Node\Expr $chain): RouteGroupAttributes
    {
        $builder = new RouteGroupAttributesBuilder;

        foreach ($this->setters($chain) as [$attribute, $nodes]) {
            // fluent setters: a repeated prefix, name or controller overwrites, middleware accumulates
            $builder->set($attribute, $nodes, accumulate: true);
        }

        return $builder->build();
    }

    /**
     * The recognised setters of a fluent chain, innermost (first written) first.
     *
     * @return list<array{0: RouteGroupAttribute, 1: list<Node\Expr>}>
     */
    private function setters(Node\Expr $chain): array
    {
        $setters = [];
        $current = $chain;

        // The chain is walked outermost call first; the terminal call is the
        // static `Route::prefix(...)` that roots it.
        while ($current instanceof Node\Expr\MethodCall || $current instanceof Node\Expr\StaticCall) {
            $attribute = $current->name instanceof Node\Identifier
                ? RouteGroupAttribute::fromSetter($current->name->toString())
                : null;

            // a call that is not an attribute setter (->where(), ->domain(), ...)
            if ($attribute !== null) {
                $setters[] = [$attribute, $this->argumentNodes($current->args)];
            }

            $current = $current instanceof Node\Expr\MethodCall ? $current->var : null;
        }

        return array_values(collect($setters)->reverse()->all());
    }

    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     * @return list<Node\Expr>
     */
    private function argumentNodes(array $args): array
    {
        $nodes = [];
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $nodes[] = $arg->value;
            }
        }

        return $nodes;
    }
}
