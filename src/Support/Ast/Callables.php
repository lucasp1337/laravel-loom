<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Reads callable-shaped expressions: `[Class::class, 'method']` tuples,
 * `Closure::fromCallable(...)` and first-class callable syntax.
 *
 * @internal
 */
final class Callables
{
    /**
     * Extract a `[Class::class, 'method']` callable tuple.
     *
     * @return array{class: string, method: string}|null
     */
    public static function tuple(Node\Expr\Array_ $array): ?array
    {
        if (count($array->items) !== 2) {
            return null;
        }

        $classFqcn = ClassRef::fromClassConstant($array->items[0]->value);
        if ($classFqcn === null) {
            return null;
        }
        $methodNode = $array->items[1]->value;
        if (! $methodNode instanceof Node\Scalar\String_) {
            return null;
        }

        return ['class' => $classFqcn, 'method' => $methodNode->value];
    }

    /**
     * Resolve a callable-shaped listener expression to a listener FQCN and
     * method name. Handles two shapes:
     *
     *   - `Closure::fromCallable([Foo::class, 'method'])` → Foo::method
     *   - `Closure::fromCallable([Foo::class])`           → Foo::handle
     *   - `Foo::method(...)` first-class callable          → Foo::method
     *
     * Returns null for anything else (dynamic args, instance callables,
     * string callables, variable arrays).
     *
     * @return array{listener: string, method: string}|null
     */
    public static function listener(Node\Expr $value): ?array
    {
        if (! $value instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $value->class instanceof Node\Name) {
            return null;
        }
        if (! $value->name instanceof Node\Identifier) {
            return null;
        }

        $args = Args::of($value->args);

        // Shape A: Closure::fromCallable([Foo::class, 'method']) / ([Foo::class]).
        if (! $args->isFirstClassCallable()
            && Fqcn::same($value->class->toString(), 'Closure')
            && $value->name->toString() === 'fromCallable'
            && $args->count() === 1
        ) {
            $array = $args->valueAt(0);
            if (! $array instanceof Node\Expr\Array_) {
                return null;
            }

            return self::fromArray($array);
        }

        // Shape B: Foo::method(...) first-class callable.
        if ($args->isFirstClassCallable()) {
            return [
                'listener' => $value->class->toString(),
                'method' => $value->name->toString(),
            ];
        }

        return null;
    }

    /**
     * Resolve a `[Foo::class, 'method']` or `[Foo::class]` array to a listener
     * FQCN and method (defaulting to 'handle' for the single-element form).
     *
     * @return array{listener: string, method: string}|null
     */
    private static function fromArray(Node\Expr\Array_ $array): ?array
    {
        $tuple = self::tuple($array);
        if ($tuple !== null) {
            return ['listener' => $tuple['class'], 'method' => $tuple['method']];
        }

        if (count($array->items) === 1) {
            $listener = ClassRef::fromClassConstant($array->items[0]->value);
            if ($listener !== null) {
                return ['listener' => $listener, 'method' => 'handle'];
            }
        }

        return null;
    }
}
