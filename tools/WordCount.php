<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools;

/**
 * Words in a markdown page, for the docs word budgets: whitespace-separated
 * tokens that contain a letter or digit, so table pipes, rules and bare list
 * markers do not count.
 */
final class WordCount
{
    public static function of(string $markdown): int
    {
        $count = 0;
        foreach (preg_split('/\s+/u', $markdown, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
