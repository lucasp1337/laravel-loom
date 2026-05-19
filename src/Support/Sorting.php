<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Comparator factories for stable, key-tuple-based sorts.
 */
final class Sorting
{
    /**
     * Comparator that sorts associative-array rows by the given keys in order.
     * Missing keys default to 0 for 'line', '' otherwise — so the comparator
     * never compares mixed types.
     *
     * @param  list<string>  $keys
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    public static function byKeys(array $keys): callable
    {
        return function (array $a, array $b) use ($keys): int {
            $aTuple = [];
            $bTuple = [];
            foreach ($keys as $key) {
                $default = $key === 'line' ? 0 : '';
                $aTuple[] = $a[$key] ?? $default;
                $bTuple[] = $b[$key] ?? $default;
            }

            return $aTuple <=> $bTuple;
        };
    }
}
