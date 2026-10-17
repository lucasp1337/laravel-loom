<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast\Route;

use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\RouteGroupAttribute;
use PhpParser\Node;

/**
 * Collects one group's attributes from its array config or fluent setters,
 * applied in source order the way Laravel's `RouteRegistrar::attribute()`
 * does: a repeated prefix, name, controller or middleware overwrites the
 * earlier one. Accumulation happens elsewhere: nested groups
 * ({@see RouteGroupContext::merge()}) and a route's own `->middleware()`.
 *
 * A value that is not a static literal is never guessed: the attribute is
 * left unset and recorded as unresolved.
 *
 * @internal
 */
final class RouteGroupAttributesBuilder
{
    private ?string $prefix = null;

    private ?string $name = null;

    private ?Node\Expr $controllerNode = null;

    /** @var list<Node\Expr> */
    private array $middlewareNodes = [];

    /** @var list<Node\Expr> */
    private array $excludedNodes = [];

    /** @var array<string, bool> unresolved flag by attribute value, set by the latest assignment */
    private array $unresolved = [];

    /** @var array<string, Node\Expr> the expression behind each unresolved flag */
    private array $unresolvedNodes = [];

    /**
     * @param  list<Node\Expr>  $nodes  the setter's argument nodes; single-valued attributes read the first
     */
    public function set(RouteGroupAttribute $attribute, array $nodes): void
    {
        $first = $nodes[0] ?? null;

        match ($attribute) {
            // Route::prefix('x') / ['prefix' => 'x']: literal trimmed of slashes, empty means none
            RouteGroupAttribute::PREFIX => $this->prefix = $this->normalisePrefix($this->literal($attribute, $first)),
            // ->name('x.') / ->as('x.') / ['as' => 'x.']: kept as written, it may end in '.'
            RouteGroupAttribute::NAME => $this->name = $this->normaliseName($this->literal($attribute, $first)),
            // ->controller(Ctrl::class): resolved to an FQCN later, once NameResolver has rewritten the name
            RouteGroupAttribute::CONTROLLER => $this->setController($first),
            // ->middleware(...) / ['middleware' => ...]: names resolved later, like the controller
            RouteGroupAttribute::MIDDLEWARE => $this->setMiddleware($nodes),
            // ->withoutMiddleware(...) / ['excluded_middleware' => ...]: exclusions accumulate
            RouteGroupAttribute::WITHOUT_MIDDLEWARE => $this->addExcluded($nodes),
            // only ever produced for a non-array Route::group() config, never set here
            RouteGroupAttribute::ATTRIBUTES => null,
        };
    }

    /** The whole config of `Route::group($attributes, ...)` is not an array literal. */
    public function markAttributesUnresolved(?Node\Expr $node = null): void
    {
        $this->unresolved[RouteGroupAttribute::ATTRIBUTES->value] = true;
        if ($node !== null) {
            $this->unresolvedNodes[RouteGroupAttribute::ATTRIBUTES->value] = $node;
        }
    }

    public function build(int $line = 0): RouteGroupAttributes
    {
        $unresolved = [];
        foreach ($this->unresolved as $value => $isUnresolved) {
            if ($isUnresolved) {
                $unresolved[] = RouteGroupAttribute::from($value);
            }
        }

        return new RouteGroupAttributes($this->prefix, $this->name, $this->controllerNode, $this->middlewareNodes, $this->excludedNodes, $unresolved, $this->unresolvedNodes, $line);
    }

    /** The literal string of a prefix or name; a non-literal is recorded as unresolved, never guessed. */
    private function literal(RouteGroupAttribute $attribute, ?Node\Expr $node): ?string
    {
        $literal = Literal::string($node);
        $this->unresolved[$attribute->value] = $literal === null;
        $this->remember($attribute, $node, $literal === null);

        return $literal;
    }

    private function setController(?Node\Expr $node): void
    {
        $this->controllerNode = $node;
        // Anything but `Class::class` has no FQCN to apply.
        $this->unresolved[RouteGroupAttribute::CONTROLLER->value] = ClassRef::fromClassConstant($node) === null;
        $this->remember(RouteGroupAttribute::CONTROLLER, $node, ClassRef::fromClassConstant($node) === null);
    }

    /** @param  list<Node\Expr>  $nodes */
    private function setMiddleware(array $nodes): void
    {
        // the latest assignment replaces the earlier list and its unresolved flag
        $unreadable = ValueLists::unreadableMiddleware($nodes);
        $this->unresolved[RouteGroupAttribute::MIDDLEWARE->value] = $unreadable !== [];
        $this->remember(RouteGroupAttribute::MIDDLEWARE, $unreadable[0] ?? null, $unreadable !== []);
        $this->middlewareNodes = $nodes;
    }

    /** @param  list<Node\Expr>  $nodes */
    private function addExcluded(array $nodes): void
    {
        $unreadable = ValueLists::unreadableMiddleware($nodes);
        // exclusions accumulate, so an unreadable one stays flagged
        if ($unreadable !== []) {
            $this->unresolved[RouteGroupAttribute::WITHOUT_MIDDLEWARE->value] = true;
            $this->remember(RouteGroupAttribute::WITHOUT_MIDDLEWARE, $unreadable[0], true);
        }
        $this->excludedNodes = [...$this->excludedNodes, ...$nodes];
    }

    /** Keep the expression behind an unresolved flag, drop it when the latest assignment is readable. */
    private function remember(RouteGroupAttribute $attribute, ?Node\Expr $node, bool $unresolved): void
    {
        if ($unresolved && $node !== null) {
            $this->unresolvedNodes[$attribute->value] = $node;

            return;
        }
        unset($this->unresolvedNodes[$attribute->value]);
    }

    private function normalisePrefix(?string $value): ?string
    {
        $trimmed = trim($value ?? '', '/');

        return $trimmed === '' ? null : $trimmed;
    }

    private function normaliseName(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
