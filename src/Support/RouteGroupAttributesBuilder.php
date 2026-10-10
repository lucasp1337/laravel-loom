<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Arr;
use Lucasp\Loom\Dto\RouteGroupAttributes;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\ValueLists;
use PhpParser\Node;

/**
 * Collects one group's attributes from its array config or fluent setters,
 * applied in source order the way Laravel's `RouteRegistrar` does: a repeated
 * prefix, name or controller overwrites the earlier one, middleware either
 * accumulates (fluent setters) or is replaced (array config).
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

    /** @var array<string, bool> unresolved flag by attribute value, set by the latest assignment */
    private array $unresolved = [];

    /**
     * @param  list<Node\Expr>  $nodes  the setter's argument nodes; single-valued attributes read the first
     * @param  bool  $accumulate  middleware appends to earlier setters instead of replacing them
     */
    public function set(RouteGroupAttribute $attribute, array $nodes, bool $accumulate): void
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
            RouteGroupAttribute::MIDDLEWARE => $this->setMiddleware($nodes, $accumulate),
            // only ever produced for a non-array Route::group() config, never set here
            RouteGroupAttribute::ATTRIBUTES => null,
        };
    }

    /** The whole config of `Route::group($attributes, ...)` is not an array literal. */
    public function markAttributesUnresolved(): void
    {
        $this->unresolved[RouteGroupAttribute::ATTRIBUTES->value] = true;
    }

    public function build(): RouteGroupAttributes
    {
        $unresolved = [];
        foreach ($this->unresolved as $value => $isUnresolved) {
            if ($isUnresolved) {
                $unresolved[] = RouteGroupAttribute::from($value);
            }
        }

        return new RouteGroupAttributes($this->prefix, $this->name, $this->controllerNode, $this->middlewareNodes, $unresolved);
    }

    /** The literal string of a prefix or name; a non-literal is recorded as unresolved, never guessed. */
    private function literal(RouteGroupAttribute $attribute, ?Node\Expr $node): ?string
    {
        $literal = Literal::string($node);
        $this->unresolved[$attribute->value] = $literal === null;

        return $literal;
    }

    private function setController(?Node\Expr $node): void
    {
        $this->controllerNode = $node;
        // Anything but `Class::class` has no FQCN to apply.
        $this->unresolved[RouteGroupAttribute::CONTROLLER->value] = ClassRef::fromClassConstant($node) === null;
    }

    /** @param  list<Node\Expr>  $nodes */
    private function setMiddleware(array $nodes, bool $accumulate): void
    {
        $key = RouteGroupAttribute::MIDDLEWARE->value;
        $unreadable = Arr::where($nodes, fn (Node\Expr $node): bool => ! $this->isStaticMiddleware($node)) !== [];

        $this->unresolved[$key] = $unreadable || ($accumulate && ($this->unresolved[$key] ?? false));
        $this->middlewareNodes = $accumulate ? [...$this->middlewareNodes, ...$nodes] : $nodes;
    }

    /** True when {@see ValueLists::middleware()} can read every name in the node. */
    private function isStaticMiddleware(Node\Expr $node): bool
    {
        // 'auth' or Foo::class
        if ($node instanceof Node\Scalar\String_ || ClassRef::fromClassConstant($node) !== null) {
            return true;
        }
        // a variable, call or concatenation
        if (! $node instanceof Node\Expr\Array_) {
            return false;
        }

        foreach ($node->items as $item) {
            // ['alias' => ...] and [...$spread] hold names Loom cannot read
            if ($item->key !== null || $item->unpack) {
                return false;
            }
            // an item that is neither a string nor Foo::class
            if (! $item->value instanceof Node\Scalar\String_ && ClassRef::fromClassConstant($item->value) === null) {
                return false;
            }
        }

        return true;
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
