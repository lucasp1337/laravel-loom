<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\DispatchOverrides;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\Literal;
use PhpParser\Node;

/**
 * Maps a fluent dispatch chain's `->method()` links to a {@see DispatchOverrides}.
 *
 * This is the single source of truth for the recognised modifier-method table.
 * It knows nothing about AST traversal direction or dispatch forms — callers
 * hand it a flat, source-order list of {@see Node\Expr\MethodCall} links and it
 * pulls literal argument values via {@see Literal}. Unknown methods, and
 * recognised methods whose argument is not a static literal, are ignored.
 *
 * "Last literal wins per key": when the same key appears twice (rare), the
 * later link in source order overwrites the earlier one. Keeping the rule this
 * simple avoids precedence machinery; chains in practice set each key once.
 *
 * @internal
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

            $argValue = Args::of($link->args)->valueAt(0);

            switch ($link->name->toString()) {
                case 'locale':
                    $value = Literal::string($argValue);
                    if ($value !== null) {
                        $locale = $value;
                    }
                    break;
                case 'mailer':
                    $value = Literal::string($argValue);
                    if ($value !== null) {
                        $mailer = $value;
                    }
                    break;
                case 'onConnection':
                    $value = Literal::string($argValue);
                    if ($value !== null) {
                        $connection = $value;
                    }
                    break;
                case 'onQueue':
                    $value = Literal::string($argValue);
                    if ($value !== null) {
                        $queue = $value;
                    }
                    break;
                case 'delay':
                    // Only integer-literal seconds; `now()->addMinutes(...)`,
                    // variables, etc. are intentionally not captured.
                    $value = Literal::int($argValue);
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

    /**
     * The execution mode a PendingDispatch chain selects. `->afterResponse()`
     * and `->afterResponse(true)` yield AFTER_RESPONSE; `->afterResponse(false)`
     * cancels an earlier call; a non-literal argument is ignored. Last literal
     * wins. `PendingBatch::dispatchAfterResponse()` also selects it.
     *
     * @param  list<Node\Expr\MethodCall>  $links
     */
    public static function mode(array $links): ?DispatchMode
    {
        $mode = null;

        foreach ($links as $link) {
            if (! $link->name instanceof Node\Identifier) {
                continue;
            }

            if ($link->name->toString() === 'dispatchAfterResponse') {
                $mode = DispatchMode::AFTER_RESPONSE;

                continue;
            }

            if ($link->name->toString() !== 'afterResponse') {
                continue;
            }

            $first = Args::of($link->args)->valueAt(0);
            if ($first === null) {
                $mode = DispatchMode::AFTER_RESPONSE;

                continue;
            }

            $value = Literal::bool($first);
            if ($value !== null) {
                $mode = $value ? DispatchMode::AFTER_RESPONSE : null;
            }
        }

        return $mode;
    }
}
