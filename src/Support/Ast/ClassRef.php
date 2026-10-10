<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * Resolves class references (`Foo::class`, `new Foo`) to the name php-parser
 * holds for them. Run after NameResolver for fully qualified names.
 *
 * @internal
 */
final class ClassRef
{
    /** Resolve `Class::class` to the class name. */
    public static function fromClassConstant(?Node $expr): ?string
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
    public static function fromInstanceOrConstant(?Node $expr): ?string
    {
        // Unwrap fluent chains: (new X)->locale('es')->onQueue('q') resolves to X.
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        return self::fromClassConstant($expr);
    }

    /**
     * `Class::class` or an array of `Class::class` items; other items are skipped.
     *
     * @return list<string>
     */
    public static function listFrom(Node\Expr $expr): array
    {
        $single = self::fromClassConstant($expr);
        if ($single !== null) {
            return [$single];
        }

        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }

        $result = [];
        foreach ($expr->items as $item) {
            $fqcn = self::fromClassConstant($item->value);
            if ($fqcn !== null) {
                $result[] = $fqcn;
            }
        }

        return $result;
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
}
