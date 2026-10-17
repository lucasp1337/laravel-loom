<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * Reads list-shaped literal arguments: notification channels and route
 * middleware.
 *
 * @internal
 */
final class ValueLists
{
    /**
     * Extract a notification channel list from an array literal whose items are
     * string literals (lowercased to match Laravel channel naming) and/or
     * `Class::class` constants (resolved to FQCN).
     *
     * Returns null when $expr is not an array literal, or any item is keyed or
     * not a static literal, signalling the channel set cannot be resolved
     * statically. Callers decide what null means (absent vs. dynamic).
     *
     * @return list<string>|null
     */
    public static function channels(Node\Expr $expr): ?array
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

            $fqcn = ClassRef::fromClassConstant($value);
            if ($fqcn !== null) {
                $channels[] = $fqcn;

                continue;
            }

            return null;
        }

        return $channels;
    }

    /**
     * Resolve middleware argument nodes to verbatim identifier strings.
     *
     * Each node is one `->middleware(...)` / `'middleware' => ...` value:
     *   - String_           -> the literal ('auth', 'throttle:60,1', params kept)
     *   - ClassConstFetch   -> FQCN via ClassRef::fromClassConstant
     *   - Concat            -> folded when every part is a string or `Class::class`
     *   - Array_            -> each item resolved the same way
     * Anything else (variables, calls, keyed array entries) is skipped here and
     * listed by {@see unreadableMiddleware()}. Groups ('web'/'api') and aliases
     * are NOT expanded.
     *
     * @param  list<Node\Expr>  $nodes
     * @return list<string>
     */
    public static function middleware(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            foreach (self::middlewareNames($node) as $name) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The middleware expressions {@see middleware()} cannot read, so a caller
     * can report them instead of dropping them.
     *
     * @param  list<Node\Expr>  $nodes
     * @return list<Node\Expr>
     */
    public static function unreadableMiddleware(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            // `[...]`: judge each item; a keyed or spread item is unreadable as a whole
            if ($node instanceof Node\Expr\Array_) {
                foreach ($node->items as $item) {
                    if ($item->key !== null || $item->unpack || self::foldString($item->value) === null) {
                        $out[] = $item->value;
                    }
                }

                continue;
            }

            if (self::foldString($node) === null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** A string literal, `Class::class`, or a `.` concatenation of them; null otherwise. */
    public static function foldString(Node\Expr $node): ?string
    {
        // 'auth'
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        // Foo::class
        if ($node instanceof Node\Expr\ClassConstFetch) {
            return ClassRef::fromClassConstant($node);
        }

        // Foo::class.':admin'
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::foldString($node->left);
            $right = $left === null ? null : self::foldString($node->right);

            return $right === null ? null : $left.$right;
        }

        return null;
    }

    /**
     * Flatten a scheduled command's `$parameters` array into strings. Plain
     * items emit their literal value; keyed items emit "key=value". Items that
     * are not string, int or boolean literals are skipped.
     *
     * @return list<string>
     */
    public static function commandArguments(?Node\Expr $array): array
    {
        if (! $array instanceof Node\Expr\Array_) {
            return [];
        }

        $out = [];
        foreach ($array->items as $item) {
            $value = self::scalarText($item->value);
            if ($value === null) {
                continue;
            }

            if ($item->key === null) {
                $out[] = $value;

                continue;
            }

            $key = self::scalarText($item->key);
            if ($key !== null) {
                $out[] = $key.'='.$value;
            }
        }

        return $out;
    }

    /** A string, int or `true`/`false` literal as text; null when unresolvable. */
    private static function scalarText(Node\Expr $node): ?string
    {
        $scalar = Literal::scalar($node);
        if ($scalar !== null) {
            return (string) $scalar;
        }

        $bool = Literal::bool($node);

        return $bool === null ? null : ($bool ? 'true' : 'false');
    }

    /** @return list<string> */
    private static function middlewareNames(Node\Expr $node): array
    {
        if ($node instanceof Node\Expr\Array_) {
            $out = [];
            foreach ($node->items as $item) {
                $name = $item->key === null ? self::foldString($item->value) : null;
                if ($name !== null) {
                    $out[] = $name;
                }
            }

            return $out;
        }

        $name = self::foldString($node);

        return $name === null ? [] : [$name];
    }
}
