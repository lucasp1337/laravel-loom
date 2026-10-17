<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast\Route;

use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Literal;
use Lucasp\Loom\Support\Ast\ValueLists;
use PhpParser\Node;

/**
 * The options of one resource registration, built the way Laravel builds them:
 * the literal options array first, then each `PendingResourceRegistration`
 * modifier in source order (`->only()`, `->names()`, `->middleware()`, ...).
 * A value that is not a literal is never guessed; it sets {@see $dynamic} and
 * leaves the option as it was.
 *
 * @internal
 */
final class ResourceOptions
{
    /** @var ?list<string> */
    public ?array $only = null;

    /** @var list<string> */
    public array $except = [];

    /** @var string|array<string, string>|null */
    public string|array|null $names = null;

    /** @var string|array<string, string>|null */
    public string|array|null $parameters = null;

    public bool $shallow = false;

    public bool $creatable = false;

    public bool $destroyable = false;

    /** @var ?list<string> */
    public ?array $middleware = null;

    /** @var array<string, list<string>> */
    public array $middlewareFor = [];

    /** @var list<string> */
    public array $excluded = [];

    /** @var array<string, list<string>> */
    public array $excludedFor = [];

    /** Name prefix of the `as` option / `Route::name()` attribute, without the joining dot. */
    public ?string $as = null;

    /** True when some option was not a literal. */
    public bool $dynamic = false;

    /** Read the `$options` array argument. */
    public function readArray(?Node\Expr $node): void
    {
        // argument omitted
        if ($node === null) {
            return;
        }

        // a variable or call: nothing in it can be read
        if (! $node instanceof Node\Expr\Array_) {
            $this->dynamic = true;

            return;
        }

        foreach ($node->items as $item) {
            $key = $item->key === null ? null : Literal::string($item->key);
            // a spread or a non-string key
            if ($key === null || $item->unpack) {
                $this->dynamic = true;

                continue;
            }

            $this->setOption($key, $item->value);
        }
    }

    /**
     * Apply one `PendingResourceRegistration` modifier.
     *
     * @param  string  $method  the modifier's name
     */
    public function applyModifier(string $method, Args $args): void
    {
        match ($method) {
            // ->only('a', 'b') / ->only(['a', 'b'])
            'only' => $this->only = $this->argList($args) ?? $this->only,
            // ->except(...)
            'except' => $this->except = $this->argList($args) ?? $this->except,
            // ->names('x') / ->names(['index' => 'x.list'])
            'names' => $this->names = $this->nameMap($args->valueAt(0)) ?? $this->names,
            // ->name('index', 'x.list')
            'name' => $this->setName($args),
            // ->parameters('singular') / ->parameters(['photos' => 'photo_id'])
            'parameters' => $this->parameters = $this->nameMap($args->valueAt(0)) ?? $this->parameters,
            // ->parameter('photos', 'photo_id')
            'parameter' => $this->setParameter($args),
            // ->middleware(...) replaces and refreshes the per-method lists
            'middleware' => $this->setMiddleware($args),
            // ->middlewareFor('show', 'auth')
            'middlewareFor' => $this->setMiddlewareFor($args),
            // ->withoutMiddleware(...) accumulates
            'withoutMiddleware' => $this->excluded = [...$this->excluded, ...$this->middlewareOf($args->values())],
            // ->withoutMiddlewareFor('show', 'auth')
            'withoutMiddlewareFor' => $this->setExcludedFor($args),
            // ->shallow() / ->shallow(false)
            'shallow' => $this->shallow = $this->flag($args->valueAt(0)),
            // singleton ->creatable() / ->destroyable()
            'creatable' => $this->creatable = true,
            'destroyable' => $this->destroyable = true,
            // where, missing, scoped, withTrashed, ... do not change routes, names or middleware
            default => null,
        };
    }

    private function setOption(string $key, Node\Expr $value): void
    {
        match ($key) {
            'only' => $this->only = $this->valueList($value) ?? $this->only,
            'except' => $this->except = $this->valueList($value) ?? $this->except,
            'names' => $this->names = $this->nameMap($value) ?? $this->names,
            'parameters' => $this->parameters = $this->nameMap($value) ?? $this->parameters,
            'middleware' => $this->middleware = $this->middlewareOf([$value]),
            'excluded_middleware' => $this->excluded = $this->middlewareOf([$value]),
            'middleware_for' => $this->middlewareFor = $this->perMethod($value) ?? $this->middlewareFor,
            'excluded_middleware_for' => $this->excludedFor = $this->perMethod($value) ?? $this->excludedFor,
            'shallow' => $this->shallow = $this->flag($value),
            // isset(): any non-null value turns the singleton variant on
            'creatable' => $this->creatable = ! $this->isNull($value),
            'destroyable' => $this->destroyable = ! $this->isNull($value),
            'as' => $this->as = Literal::string($value) ?? $this->unreadable($this->as),
            // wheres, missing, bindingFields, trashed: no effect on what Loom reports
            default => null,
        };
    }

    private function setName(Args $args): void
    {
        $method = Literal::string($args->valueAt(0));
        $name = Literal::string($args->valueAt(1));
        if ($method === null || $name === null) {
            $this->dynamic = true;

            return;
        }

        $names = is_array($this->names) ? $this->names : [];
        $names[$method] = $name;
        $this->names = $names;
    }

    private function setParameter(Args $args): void
    {
        $previous = Literal::string($args->valueAt(0));
        $new = Literal::string($args->valueAt(1));
        if ($previous === null || $new === null) {
            $this->dynamic = true;

            return;
        }

        $parameters = is_array($this->parameters) ? $this->parameters : [];
        $parameters[$previous] = $new;
        $this->parameters = $parameters;
    }

    private function setMiddleware(Args $args): void
    {
        $middleware = $this->middlewareOf($args->values());
        $this->middleware = $middleware;

        // Laravel appends the new list to every per-method list set earlier
        foreach ($this->middlewareFor as $method => $existing) {
            $this->middlewareFor[$method] = collect([...$existing, ...$middleware])->unique()->values()->all();
        }
    }

    private function setMiddlewareFor(Args $args): void
    {
        $methods = $this->argValueList($args->valueAt(0));
        $middleware = $this->middlewareOf(collect($args->values())->slice(1)->values()->all());
        if ($methods === null) {
            $this->dynamic = true;

            return;
        }

        // the resource-wide list applies to these methods too
        if ($this->middleware !== null) {
            $middleware = collect([...$this->middleware, ...$middleware])->unique()->values()->all();
        }
        foreach ($methods as $method) {
            $this->middlewareFor[$method] = $middleware;
        }
    }

    private function setExcludedFor(Args $args): void
    {
        $methods = $this->argValueList($args->valueAt(0));
        $middleware = $this->middlewareOf(collect($args->values())->slice(1)->values()->all());
        if ($methods === null) {
            $this->dynamic = true;

            return;
        }

        foreach ($methods as $method) {
            $this->excludedFor[$method] = $middleware;
        }
    }

    /**
     * Middleware names of the nodes; an unreadable entry marks the options dynamic.
     *
     * @param  list<Node\Expr>  $nodes
     * @return list<string>
     */
    private function middlewareOf(array $nodes): array
    {
        if (ValueLists::unreadableMiddleware($nodes) !== []) {
            $this->dynamic = true;
        }

        return ValueLists::middleware($nodes);
    }

    /**
     * Method names given variadically or as one array.
     *
     * @return ?list<string>
     */
    private function argList(Args $args): ?array
    {
        $out = [];
        foreach ($args->values() as $value) {
            $list = $this->valueList($value);
            if ($list === null) {
                return null;
            }
            $out = [...$out, ...$list];
        }

        return $out;
    }

    /**
     * A string literal or an array of them; null (and dynamic) otherwise.
     *
     * @return ?list<string>
     */
    private function valueList(Node\Expr $value): ?array
    {
        $string = Literal::string($value);
        if ($string !== null) {
            return [$string];
        }

        $list = Literal::strictStringList($value);
        if ($list === null) {
            $this->dynamic = true;
        }

        return $list;
    }

    /** @return ?list<string> */
    private function argValueList(?Node\Expr $value): ?array
    {
        return $value === null ? null : $this->valueList($value);
    }

    /**
     * `'x'` or `['a' => 'b']`; null (and dynamic) otherwise.
     *
     * @return string|array<string, string>|null
     */
    private function nameMap(?Node\Expr $value): string|array|null
    {
        $string = Literal::string($value);
        if ($string !== null) {
            return $string;
        }

        if (! $value instanceof Node\Expr\Array_) {
            $this->dynamic = true;

            return null;
        }

        $map = [];
        foreach ($value->items as $item) {
            $key = $item->key === null ? null : Literal::string($item->key);
            $name = Literal::string($item->value);
            if ($key === null || $name === null) {
                $this->dynamic = true;

                continue;
            }
            $map[$key] = $name;
        }

        return $map;
    }

    /**
     * `['show' => ['auth']]` as a method-to-middleware map; null (and dynamic) when not an array.
     *
     * @return ?array<string, list<string>>
     */
    private function perMethod(Node\Expr $value): ?array
    {
        if (! $value instanceof Node\Expr\Array_) {
            $this->dynamic = true;

            return null;
        }

        $map = [];
        foreach ($value->items as $item) {
            $key = $item->key === null ? null : Literal::string($item->key);
            if ($key === null) {
                $this->dynamic = true;

                continue;
            }
            $map[$key] = $this->middlewareOf([$item->value]);
        }

        return $map;
    }

    private function flag(?Node\Expr $value): bool
    {
        // ->shallow(): the argument defaults to true
        if ($value === null) {
            return true;
        }

        $bool = Literal::bool($value);
        if ($bool === null) {
            $this->dynamic = true;

            return $this->shallow;
        }

        return $bool;
    }

    private function isNull(Node\Expr $value): bool
    {
        return $value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null';
    }

    private function unreadable(?string $current): ?string
    {
        $this->dynamic = true;

        return $current;
    }
}
