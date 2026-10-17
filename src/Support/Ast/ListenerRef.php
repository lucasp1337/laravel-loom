<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use Illuminate\Support\Str;
use Lucasp\Loom\Support\Fqcn;
use PhpParser\Node;

/**
 * Reads the class and method a listener expression names, the way the
 * dispatcher resolves a listener value: `Foo::class`, a `'Foo@method'` string
 * (the part before the first `@` is the class, a bare class string means
 * `handle`), a concatenation of such parts (`Foo::class.'@method'`), a
 * `[Foo::class, 'method']` tuple, `Closure::fromCallable(...)` and first-class
 * callable syntax. Anything else (variables, calls, interpolation, `self`,
 * `static`) is null, so the caller can report it.
 *
 * @internal
 */
final class ListenerRef
{
    private const string DEFAULT_METHOD = 'handle';

    /**
     * A string built from literals, `X::class` constants and their
     * concatenation; null for any other expression.
     */
    public static function fold(Node\Expr $expr): ?string
    {
        $class = ClassRef::fromClassConstant($expr);
        if ($class !== null) {
            // `self::class` / `static::class` / `parent::class` name no fixed class here.
            return match (strtolower($class)) {
                'self', 'static', 'parent' => null,
                default => $class,
            };
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::fold($expr->left);
            $right = self::fold($expr->right);

            return $left === null || $right === null ? null : $left.$right;
        }

        return null;
    }

    /**
     * The event name an expression holds: a class constant, or a folded
     * string (class names lose a leading backslash).
     */
    public static function event(Node\Expr $expr): ?string
    {
        $folded = self::fold($expr);

        return $folded === null || $folded === '' ? null : Fqcn::normalize($folded);
    }

    /**
     * @return array{listener: string, method: string}|null
     */
    public static function listener(Node\Expr $value): ?array
    {
        if ($value instanceof Node\Expr\Array_) {
            return self::fromArray($value);
        }

        // `Closure::fromCallable([...])` and `Foo::method(...)`.
        $callable = Callables::listener($value);
        if ($callable !== null) {
            return $callable;
        }

        $folded = self::fold($value);
        if ($folded === null) {
            return null;
        }

        return self::fromString($folded);
    }

    /**
     * Splits a listener string the way the dispatcher does; null when the
     * class or an explicit method part is empty.
     *
     * @return array{listener: string, method: string}|null
     */
    public static function fromString(string $listener): ?array
    {
        // `Class::method` is not a form the dispatcher understands.
        if (Str::contains($listener, '::')) {
            return null;
        }

        $parts = Fqcn::splitAtMember($listener);
        $class = Fqcn::normalize($parts[0] ?? $listener);
        $method = $parts[1] ?? self::DEFAULT_METHOD;

        if ($class === '' || $method === '') {
            return null;
        }

        return ['listener' => $class, 'method' => $method];
    }

    /**
     * @return array{listener: string, method: string}|null
     */
    private static function fromArray(Node\Expr\Array_ $array): ?array
    {
        $count = count($array->items);
        if ($count < 1 || $count > 2) {
            return null;
        }

        $class = self::fold($array->items[0]->value);
        if ($class === null || $class === '' || Str::contains($class, '@')) {
            return null;
        }

        if ($count === 1) {
            return ['listener' => Fqcn::normalize($class), 'method' => self::DEFAULT_METHOD];
        }

        $method = Literal::string($array->items[1]->value);

        return $method === null || $method === ''
            ? null
            : ['listener' => Fqcn::normalize($class), 'method' => $method];
    }
}
