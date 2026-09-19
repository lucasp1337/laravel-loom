<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/**
 * Bounds for handler-to-dispatch hops followed when walking an event chain.
 */
final class ChainDepth
{
    public const MIN = 1;

    public const MAX = 6;

    public const DEFAULT = 3;

    public static function clamp(int $depth): int
    {
        return max(self::MIN, min(self::MAX, $depth));
    }
}
