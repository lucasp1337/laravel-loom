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
}
