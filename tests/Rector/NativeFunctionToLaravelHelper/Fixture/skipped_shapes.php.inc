<?php

namespace Lucasp\Loom\Tests\Rector\NativeFunctionToLaravelHelper\Fixture;

final class SkippedShapes
{
    /**
     * @param  list<string>  $names
     * @param  array<string, int>  $map
     * @param  list<int|string>  $mixed
     */
    public function run(string $text, string $maybeEmpty, array $names, array $map, array $mixed, mixed $unknown, float $f, callable $cb, int $key): void
    {
        // Empty needle behaves differently in Str::contains.
        $a = str_contains($text, '');
        $b = str_contains($text, $maybeEmpty);
        // Helper would also pass the key to a string callable.
        $c = array_map('trim', $names);
        $d = array_filter($names);
        $e = array_map(fn (string $s, int $k): string => $s, $names);
        // Float and untyped keys, untyped arrays.
        $g = array_key_exists($f, $map);
        $h = array_key_exists($text, $unknown);
        // A callable needle would be treated as a callback by Collection.
        $i = in_array($cb, $names, true);
        $j = in_array($text, $names, $unknown);
        // Native array_unique compares as strings: not strictly equal for mixed values.
        $k = array_unique($mixed);
        // Collection slice/reverse keep keys: not equal for a non-list.
        $l = array_slice($map, 1);
        $m = array_reverse($map);
        $n = array_slice($names, 1, 2, $unknown);
        // Untyped or non-numeric array_sum, key-passing reduce, one-argument implode.
        $o = array_sum($mixed);
        $p = array_reduce($names, fn (string $c, string $s, int $k): string => $c, '');
        $q = implode($names);
        // Named or spread arguments.
        $r = in_array(needle: $text, haystack: $names);
        $s = array_merge(...[$names, $names]);
        // Used as a value, extra flags, or a non-variable target.
        $t = sort($names);
        sort($names, SORT_STRING);
        $u = ['x' => $names];
        sort($u['x']);
    }
}
