<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * Reads statically known scalar literals out of expressions. Every method
 * returns null for anything that is not a plain literal.
 *
 * @internal
 */
final class Literal
{
    /** A string, int (signed) or null. */
    public static function scalar(?Node\Expr $node): string|int|null
    {
        return self::string($node) ?? self::int($node);
    }

    /** A `true` / `false` constant. */
    public static function bool(?Node\Expr $node): ?bool
    {
        if (! $node instanceof Node\Expr\ConstFetch) {
            return null;
        }

        return match (strtolower($node->name->getLast())) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    public static function string(?Node\Expr $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    public static function int(?Node\Expr $node): ?int
    {
        // `42`
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }

        // `-42`
        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\Int_) {
            return -$node->expr->value;
        }

        return null;
    }

    /**
     * Every item of an array literal as a string, or null when the node is not
     * an array or holds a non-string item.
     *
     * @return list<string>|null
     */
    public static function strictStringList(?Node\Expr $node): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }

        $values = [];
        foreach ($node->items as $item) {
            $string = self::string($item->value);
            if ($string === null) {
                return null;
            }
            $values[] = $string;
        }

        return $values;
    }

    /**
     * Every item of an array literal as an int, or null when the node is not
     * an array, is empty, or holds a non-integer item.
     *
     * @return list<int>|null
     */
    public static function intList(?Node\Expr $node): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }

        $values = [];
        foreach ($node->items as $item) {
            $int = self::int($item->value);
            if ($int === null) {
                return null;
            }
            $values[] = $int;
        }

        return $values === [] ? null : $values;
    }

    /**
     * The string-literal items of an array literal, skipping any other item;
     * empty when the node is not an array.
     *
     * @return list<string>
     */
    public static function stringItems(?Node\Expr $node): array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $values = [];
        foreach ($node->items as $item) {
            $string = self::string($item->value);
            if ($string !== null) {
                $values[] = $string;
            }
        }

        return $values;
    }
}
