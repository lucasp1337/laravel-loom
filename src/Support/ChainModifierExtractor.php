<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\DispatchOverrides;
use PhpParser\Node;

/**
 * Maps a fluent dispatch chain's `->method()` links to a {@see DispatchOverrides}.
 *
 * This is the single source of truth for the recognised modifier-method table.
 * It knows nothing about AST traversal direction or dispatch forms — callers
 * hand it a flat, source-order list of {@see Node\Expr\MethodCall} links and it
 * pulls literal argument values via {@see AstHelpers}. Unknown methods, and
 * recognised methods whose argument is not a static literal, are ignored.
 *
 * "Last literal wins per key": when the same key appears twice (rare), the
 * later link in source order overwrites the earlier one. Keeping the rule this
 * simple avoids precedence machinery; chains in practice set each key once.
 */
final class ChainModifierExtractor
{
    /**
     * @param  list<Node\Expr\MethodCall>  $links
     */
    public static function extract(array $links): DispatchOverrides
    {
        $locale = null;
        $mailer = null;
        $connection = null;
        $queue = null;
        $delay = null;
        $afterCommit = null;

        foreach ($links as $link) {
            if (! $link->name instanceof Node\Identifier) {
                continue;
            }

            $args = $link->args;
            $first = $args[0] ?? null;
            $argValue = $first instanceof Node\Arg ? $first->value : null;

            switch ($link->name->toString()) {
                case 'locale':
                    $value = AstHelpers::scalarString($argValue);
                    if ($value !== null) {
                        $locale = $value;
                    }
                    break;
                case 'mailer':
                    $value = AstHelpers::scalarString($argValue);
                    if ($value !== null) {
                        $mailer = $value;
                    }
                    break;
                case 'onConnection':
                    $value = AstHelpers::scalarString($argValue);
                    if ($value !== null) {
                        $connection = $value;
                    }
                    break;
                case 'onQueue':
                    $value = AstHelpers::scalarString($argValue);
                    if ($value !== null) {
                        $queue = $value;
                    }
                    break;
                case 'delay':
                    // Only integer-literal seconds; `now()->addMinutes(...)`,
                    // variables, etc. are intentionally not captured.
                    $value = AstHelpers::scalarInt($argValue);
                    if ($value !== null) {
                        $delay = $value;
                    }
                    break;
                case 'afterCommit':
                    // Presence implies true; false is never emitted.
                    $afterCommit = true;
                    break;
            }
        }

        return new DispatchOverrides(
            locale: $locale,
            mailer: $mailer,
            connection: $connection,
            queue: $queue,
            delay: $delay,
            afterCommit: $afterCommit,
        );
    }
}
