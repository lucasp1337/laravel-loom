<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Node;

/**
 * Stateless helpers for nikic/php-parser AST nodes that recur across
 * multiple visitors. Each scanner had its own private copy of these —
 * centralised here so a refactor of one helper doesn't need to be
 * threaded through eight files.
 */
final class AstHelpers
{
    /**
     * Resolve `Class::class` to the FQCN string. Returns null when the
     * expression isn't a `ClassConstFetch` of the form `Name::class`.
     */
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

    /**
     * Resolve `new X()` or `X::class` to the FQCN string. Used everywhere
     * a dispatch target is expected as an argument (events, jobs,
     * mailables, notifications, schedule entries). Returns null when
     * the expression is anything else (variable, function call, ternary,
     * ...).
     */
    public static function resolveStaticClass(?Node $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        return self::classConstFqcn($expr);
    }

    /**
     * Resolve a scalar literal — string, integer (positive or negative),
     * or null literal — to its PHP value. Used by every class-visitor
     * extracting declared property defaults (queue_config, etc.).
     */
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
        // Negative integer literal — PHP-Parser models `-3` as
        // UnaryMinus(LNumber(3)).
        if ($node instanceof Node\Expr\UnaryMinus
            && $node->expr instanceof Node\Scalar\Int_
        ) {
            return -$node->expr->value;
        }

        return null;
    }

    /**
     * Read a string-only scalar from an Arg or expression.
     */
    public static function scalarString(?Node $node): ?string
    {
        if ($node instanceof Node\Arg) {
            $node = $node->value;
        }

        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    /**
     * Read an integer-only scalar (positive or negative) from an Arg or
     * expression.
     */
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
     * Extract a `[Class::class, 'method']` callable tuple from an array
     * literal. Returns the resolved FQCN + method when the shape matches
     * (exactly two items: ClassConstFetch then String_), otherwise null.
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
     * Extract a list of class FQCNs from an expression that is either a
     * single `Class::class` or an array literal of `Class::class` items.
     * Used by `Model::observe()` and `#[ObservedBy(...)]`. Returns an
     * empty list when nothing resolves.
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
     * True when the class directly lists $interfaceFqcn in its
     * `implements` clause. Direct only — does not chase parents. Use
     * `ClassHierarchyResolver::implementsInterface` for transitive.
     */
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
