<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Dto\DispatchOverrides;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Support\Ast\CallSite;
use Lucasp\Loom\Support\Ast\Literal;

/**
 * Maps a fluent dispatch chain's `->method()` links to a {@see DispatchOverrides}.
 *
 * This is the single source of truth for the recognised modifier-method table.
 * It knows nothing about AST traversal direction or dispatch forms — callers
 * hand it a flat, source-order list of {@see CallSite} links and it
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
     * @param  list<CallSite>  $links
     */
    public static function extract(array $links): DispatchOverrides
    {
        /** @var array<string, string> $text string overrides keyed by DispatchModifier value */
        $text = [];
        $delay = null;
        $afterCommit = null;

        foreach ($links as $link) {
            $name = $link->name();
            $modifier = $name === null ? null : DispatchModifier::tryFrom($name);
            if ($modifier === null) {
                continue;
            }

            $argValue = $link->args()->valueAt(0);

            // A non-literal argument leaves the earlier literal (or null) in place.
            match ($modifier) {
                DispatchModifier::LOCALE,
                DispatchModifier::MAILER,
                DispatchModifier::CONNECTION,
                DispatchModifier::QUEUE => $text[$modifier->value] = Literal::string($argValue) ?? ($text[$modifier->value] ?? null),
                DispatchModifier::DELAY => $delay = Literal::int($argValue) ?? $delay,
                DispatchModifier::AFTER_COMMIT => $afterCommit = true,
            };
        }

        return new DispatchOverrides(
            locale: $text[DispatchModifier::LOCALE->value] ?? null,
            mailer: $text[DispatchModifier::MAILER->value] ?? null,
            connection: $text[DispatchModifier::CONNECTION->value] ?? null,
            queue: $text[DispatchModifier::QUEUE->value] ?? null,
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
     * @param  list<CallSite>  $links
     */
    public static function mode(array $links): ?DispatchMode
    {
        $mode = null;

        foreach ($links as $link) {
            $name = $link->name();
            $modifier = $name === null ? null : DispatchModeModifier::tryFrom($name);
            if ($modifier === null) {
                continue;
            }

            // `PendingBatch::dispatchAfterResponse()`: always selects the mode
            if ($modifier === DispatchModeModifier::DISPATCH_AFTER_RESPONSE) {
                $mode = DispatchMode::AFTER_RESPONSE;

                continue;
            }

            // `->afterResponse()`: no argument selects the mode
            $first = $link->args()->valueAt(0);
            if ($first === null) {
                $mode = DispatchMode::AFTER_RESPONSE;

                continue;
            }

            // `->afterResponse(true|false)`: a literal bool selects or cancels; anything else is ignored
            $value = Literal::bool($first);
            if ($value !== null) {
                $mode = $value ? DispatchMode::AFTER_RESPONSE : null;
            }
        }

        return $mode;
    }
}
