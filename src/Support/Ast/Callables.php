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

    /**
     * `Class::method` for an array callable whose class is a `Class::class`
     * constant or `new Class` and whose method is a string literal; null for
     * any other shape, including non-array expressions.
     */
    public static function staticTarget(Node\Expr $value): ?string
    {
        if (! $value instanceof Node\Expr\Array_ || count($value->items) !== 2) {
            return null;
        }

        $fqcn = ClassRef::fromInstanceOrConstant($value->items[0]->value);
        $method = Literal::string($value->items[1]->value);

        return $fqcn === null || $method === null ? null : $fqcn.'::'.$method;
    }

    /**
     * Resolve a route action expression to a controller FQCN and method. A
     * bare method-name string binds to $groupController (the enclosing group's
     * default controller) when one is present.
     *
     * @return array{fqcn: ?string, method: ?string}
     */
    public static function routeAction(?Node\Expr $value, ?string $groupController = null): array
    {
        $none = ['fqcn' => null, 'method' => null];

        // argument omitted, or a closure / arrow function: no controller
        if ($value === null || self::closureEndLine($value) !== null) {
            return $none;
        }

        // `[Ctrl::class, 'method']` or `[Ctrl::class]`
        if ($value instanceof Node\Expr\Array_) {
            return self::arrayRouteAction($value);
        }

        // bare `Ctrl::class`: invokable
        $fqcn = ClassRef::fromClassConstant($value);
        if ($fqcn !== null) {
            return ['fqcn' => $fqcn, 'method' => '__invoke'];
        }

        // 'Class@method' (legacy) or 'Class' (invokable string)
        $string = Literal::string($value);
        if ($string !== null) {
            return self::stringRouteAction($string, $groupController);
        }

        // variable or dynamic expression: never guess
        return $none;
    }

    /** End line of a closure or arrow function expression; null for any other node. */
    public static function closureEndLine(?Node\Expr $value): ?int
    {
        return $value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction
            ? $value->getEndLine()
            : null;
    }

    /** @return array{fqcn: ?string, method: ?string} */
    private static function arrayRouteAction(Node\Expr\Array_ $array): array
    {
        $tuple = self::tuple($array);
        if ($tuple !== null) {
            return ['fqcn' => $tuple['class'], 'method' => $tuple['method']];
        }

        // single-element `[Ctrl::class]`: invokable
        if (count($array->items) === 1) {
            $fqcn = ClassRef::fromClassConstant($array->items[0]->value);
            if ($fqcn !== null) {
                return ['fqcn' => $fqcn, 'method' => '__invoke'];
            }
        }

        return ['fqcn' => null, 'method' => null];
    }

    /** @return array{fqcn: ?string, method: ?string} */
    private static function stringRouteAction(string $action, ?string $groupController): array
    {
        $parts = Fqcn::splitAtMember($action);
        if ($parts !== null) {
            return ['fqcn' => Fqcn::normalize($parts[0]), 'method' => $parts[1]];
        }

        // bare method name under a group controller: Controller::method
        if ($groupController !== null) {
            return ['fqcn' => Fqcn::normalize($groupController), 'method' => $action];
        }

        // no '@' and no group controller: invokable controller string
        return ['fqcn' => Fqcn::normalize($action), 'method' => '__invoke'];
    }
}
