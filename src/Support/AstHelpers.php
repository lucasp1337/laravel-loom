<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Node;

/**
 * Stateless AST helpers shared by visitors.
 */
final class AstHelpers
{
    /** Resolve `Class::class` to the FQCN string. */
    public static function classConstFqcn(?Node $expr): ?string
    {
        if (! $expr instanceof Node\Expr\ClassConstFetch) {
            return null;
        }
        if (! $expr->class instanceof Node\Name) {
            return null;
        }
        if (! $expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($expr->name->toString() !== 'class') {
            return null;
        }

        return $expr->class->toString();
    }

    /** Resolve `new X()` / `X::class`, unwrapping any leading fluent `->method()` chain. */
    public static function resolveStaticClass(?Node $expr): ?string
    {
        // Unwrap fluent chains: (new X)->locale('es')->onQueue('q') resolves to X.
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        return self::classConstFqcn($expr);
    }

    /** Resolve a scalar literal — string, int (signed), or null. */
    public static function scalarLiteral(?Node $node): string|int|null
    {
        if ($node === null) {
            return null;
        }
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus
            && $node->expr instanceof Node\Scalar\Int_
        ) {
            return -$node->expr->value;
        }

        return null;
    }

    public static function scalarString(?Node $node): ?string
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }

        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    public static function scalarInt(?Node $node): ?int
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus
            && $node->expr instanceof Node\Scalar\Int_
        ) {
            return -$node->expr->value;
        }

        return null;
    }

    /**
     * Extract a `[Class::class, 'method']` callable tuple.
     *
     * @return array{class: string, method: string}|null
     */
    public static function tupleCallable(Node\Expr\Array_ $array): ?array
    {
        if (count($array->items) !== 2) {
            return null;
        }

        $classFqcn = self::classConstFqcn($array->items[0]->value);
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
    public static function callableListener(Node\Expr $value): ?array
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

        // Shape A: Closure::fromCallable([Foo::class, 'method']) / ([Foo::class]).
        if (! $value->isFirstClassCallable()
            && ltrim($value->class->toString(), '\\') === 'Closure'
            && $value->name->toString() === 'fromCallable'
            && count($value->args) === 1
        ) {
            $arg = $value->args[0];
            if (! $arg instanceof Node\Arg) {
                return null;
            }
            if (! $arg->value instanceof Node\Expr\Array_) {
                return null;
            }

            return self::callableArray($arg->value);
        }

        // Shape B: Foo::method(...) first-class callable.
        if ($value->isFirstClassCallable()) {
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
    private static function callableArray(Node\Expr\Array_ $array): ?array
    {
        $tuple = self::tupleCallable($array);
        if ($tuple !== null) {
            return ['listener' => $tuple['class'], 'method' => $tuple['method']];
        }

        if (count($array->items) === 1) {
            $listener = self::classConstFqcn($array->items[0]->value);
            if ($listener !== null) {
                return ['listener' => $listener, 'method' => 'handle'];
            }
        }

        return null;
    }

    /**
     * Extract a list of class FQCNs from `Class::class` or an array of
     * `Class::class` items.
     *
     * @return list<string>
     */
    public static function classConstList(Node\Expr $expr): array
    {
        $single = self::classConstFqcn($expr);
        if ($single !== null) {
            return [$single];
        }

        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }

        $result = [];
        foreach ($expr->items as $item) {
            $fqcn = self::classConstFqcn($item->value);
            if ($fqcn !== null) {
                $result[] = $fqcn;
            }
        }

        return $result;
    }

    /**
     * Extract a notification channel list from an array literal whose items are
     * string literals (lowercased to match Laravel channel naming) and/or
     * `Class::class` constants (resolved to FQCN).
     *
     * Returns null when $expr is not an array literal, or any item is keyed or
     * not a static literal — signalling the channel set cannot be resolved
     * statically. Callers decide what null means (absent vs. dynamic).
     *
     * @return list<string>|null
     */
    public static function channelList(Node\Expr $expr): ?array
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        $channels = [];
        foreach ($expr->items as $item) {
            if ($item->key !== null) {
                return null;
            }

            $value = $item->value;

            if ($value instanceof Node\Scalar\String_) {
                $channels[] = strtolower($value->value);

                continue;
            }

            $fqcn = self::classConstFqcn($value);
            if ($fqcn !== null) {
                $channels[] = $fqcn;

                continue;
            }

            return null;
        }

        return $channels;
    }

    /** True when the class directly declares `implements $interfaceFqcn`. */
    public static function declaresInterface(Node\Stmt\Class_ $node, string $interfaceFqcn): bool
    {
        foreach ($node->implements as $implements) {
            if ($implements->toString() === $interfaceFqcn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a visitor-emitted class record by FQCN.
     *
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<string, mixed>|null
     */
    public static function findClass(array $classes, string $fqcn): ?array
    {
        foreach ($classes as $class) {
            if (($class['fqcn'] ?? null) === $fqcn) {
                return $class;
            }
        }

        return null;
    }
}
