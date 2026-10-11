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
     *   - String_           → the literal ('auth', 'throttle:60,1', params kept)
     *   - Array_            → each item value resolved (String_ or Class::class)
     *   - ClassConstFetch   → FQCN via ClassRef::fromClassConstant
     * Unresolvable items (variables, concat, keyed array entries) are skipped.
     * Groups ('web'/'api'), aliases, and withoutMiddleware are NOT expanded.
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
        if ($node instanceof Node\Scalar\String_) {
            return [$node->value];
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            $fqcn = ClassRef::fromClassConstant($node);

            return $fqcn !== null ? [$fqcn] : [];
        }

        if ($node instanceof Node\Expr\Array_) {
            $out = [];
            foreach ($node->items as $item) {
                if ($item->key !== null) {
                    continue;
                }
                if ($item->value instanceof Node\Scalar\String_) {
                    $out[] = $item->value->value;

                    continue;
                }
                $fqcn = ClassRef::fromClassConstant($item->value);
                if ($fqcn !== null) {
                    $out[] = $fqcn;
                }
            }

            return $out;
        }

        return [];
    }
}
