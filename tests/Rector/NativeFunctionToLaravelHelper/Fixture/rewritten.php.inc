<?php

namespace Lucasp\Loom\Tests\Rector\NativeFunctionToLaravelHelper\Fixture;

final class Rewritten
{
    /**
     * @param  list<string>  $names
     * @param  array<string, int>  $map
     * @param  list<int>  $numbers
     * @param  array<string>  $other
     */
    public function run(string $text, string $needle, array $names, array $map, array $numbers, array $other, int $n): void
    {
        $a = str_contains($text, 'x');
        $b = str_starts_with($text, 'pre');
        $c = str_ends_with($text, '.php');
        $d = implode(',', $names);
        $e = array_map(fn (string $s): string => $s.'!', $names);
        $f = array_filter($numbers, fn (int $i): bool => $i > 1);
        $g = array_key_exists($text, $map);
        $h = in_array($text, $names, true);
        $i = in_array($n, $numbers);
        $j = array_search($text, $names, true);
        $k = array_unique($names);
        $l = array_merge($names, $other);
        $m = array_slice($names, 1, 2);
        $o = array_reverse($names);
        $p = array_flip($names);
        $q = array_sum($numbers);
        $r = array_reduce($numbers, fn (int $carry, int $i): int => $carry + $i, 0);
        $s = array_diff($names, $other);
        $t = array_intersect_key($map, $map);
        $u = array_combine($names, $numbers);
        $v = array_slice($map, 1, null, true);
        $w = array_reverse($map, true);
    }
}

?>
-----
<?php

namespace Lucasp\Loom\Tests\Rector\NativeFunctionToLaravelHelper\Fixture;

final class Rewritten
{
    /**
     * @param  list<string>  $names
     * @param  array<string, int>  $map
     * @param  list<int>  $numbers
     * @param  array<string>  $other
     */
    public function run(string $text, string $needle, array $names, array $map, array $numbers, array $other, int $n): void
    {
        $a = \Illuminate\Support\Str::contains($text, 'x');
        $b = \Illuminate\Support\Str::startsWith($text, 'pre');
        $c = \Illuminate\Support\Str::endsWith($text, '.php');
        $d = \Illuminate\Support\Arr::join($names, ',');
        $e = \Illuminate\Support\Arr::map($names, fn (string $s): string => $s.'!');
        $f = \Illuminate\Support\Arr::where($numbers, fn (int $i): bool => $i > 1);
        $g = \Illuminate\Support\Arr::exists($map, $text);
        $h = collect($names)->containsStrict($text);
        $i = collect($numbers)->contains($n);
        $j = collect($names)->search($text, true);
        $k = collect($names)->unique(strict: true)->all();
        $l = collect($names)->merge($other)->all();
        $m = array_values(collect($names)->slice(1, 2)->all());
        $o = array_values(collect($names)->reverse()->all());
        $p = collect($names)->flip()->all();
        $q = collect($numbers)->sum();
        $r = collect($numbers)->reduce(fn (int $carry, int $i): int => $carry + $i, 0);
        $s = collect($names)->diff($other)->all();
        $t = collect($map)->intersectByKeys($map)->all();
        $u = collect($names)->combine($numbers)->all();
        $v = collect($map)->slice(1, null)->all();
        $w = collect($map)->reverse()->all();
    }
}

?>
