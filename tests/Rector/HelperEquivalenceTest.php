<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/*
 * Guards the claim behind NativeFunctionToLaravelHelperRector: for the shapes it
 * rewrites, the Illuminate form returns exactly what the native call returned.
 */

$list = ['b', 'a', 'c', 'a'];
$map = ['x' => 3, 'y' => 1, 'z' => 2];
$gappy = [5 => 'e', 2 => 'b', 9 => 'z'];
$mixedKeys = [3 => 'c', 'k' => 'v', 1 => 'a'];

it('matches the native result for each rewritten shape', function (string $label, Closure $native, Closure $helper): void {
    expect($helper())->toBe($native(), $label);
})->with([
    'str_contains' => ['str_contains', fn () => str_contains('hello world', 'o w'), fn () => Str::contains('hello world', 'o w')],
    'str_starts_with' => ['str_starts_with', fn () => str_starts_with('hello', 'he'), fn () => Str::startsWith('hello', 'he')],
    'str_ends_with' => ['str_ends_with', fn () => str_ends_with('hello', 'xx'), fn () => Str::endsWith('hello', 'xx')],
    'implode' => ['implode', fn () => implode(', ', $list), fn () => Arr::join($list, ', ')],
    'array_map' => ['array_map', fn () => array_map(fn (int $i): int => $i * 2, $map), fn () => Arr::map($map, fn (int $i): int => $i * 2)],
    'array_filter' => ['array_filter', fn () => array_filter($map, fn (int $i): bool => $i > 1), fn () => Arr::where($map, fn (int $i): bool => $i > 1)],
    'array_key_exists' => ['array_key_exists', fn () => [array_key_exists('x', $map), array_key_exists('q', $map), array_key_exists(2, $gappy)], fn () => [Arr::exists($map, 'x'), Arr::exists($map, 'q'), Arr::exists($gappy, 2)]],
    'in_array strict' => ['in_array strict', fn () => [in_array('1', [1, 2], true), in_array('a', $list, true)], fn () => [collect([1, 2])->containsStrict('1'), collect($list)->containsStrict('a')]],
    'in_array loose' => ['in_array loose', fn () => in_array('1', [1, 2]), fn () => collect([1, 2])->contains('1')],
    'array_search' => ['array_search', fn () => [array_search('a', $list, true), array_search('q', $list), array_search('1', [1], false)], fn () => [collect($list)->search('a', true), collect($list)->search('q'), collect([1])->search('1', false)]],
    'array_unique strings' => ['array_unique', fn () => array_unique($list), fn () => collect($list)->unique(strict: true)->all()],
    'array_merge' => ['array_merge', fn () => array_merge($list, $map, $gappy), fn () => collect($list)->merge($map)->merge($gappy)->all()],
    'array_slice list' => ['array_slice', fn () => array_slice($list, 1, 2), fn () => array_values(collect($list)->slice(1, 2)->all())],
    'array_slice gappy ints' => ['array_slice gappy', fn () => array_slice($gappy, -2), fn () => array_values(collect($gappy)->slice(-2)->all())],
    'array_slice preserve' => ['array_slice preserve', fn () => array_slice($mixedKeys, 1, null, true), fn () => collect($mixedKeys)->slice(1, null)->all()],
    'array_reverse list' => ['array_reverse', fn () => array_reverse($gappy), fn () => array_values(collect($gappy)->reverse()->all())],
    'array_reverse preserve' => ['array_reverse preserve', fn () => array_reverse($mixedKeys, true), fn () => collect($mixedKeys)->reverse()->all()],
    'array_flip' => ['array_flip', fn () => array_flip($list), fn () => collect($list)->flip()->all()],
    'array_combine' => ['array_combine', fn () => array_combine(['a', 'b'], [1, 2]), fn () => collect(['a', 'b'])->combine([1, 2])->all()],
    'array_diff' => ['array_diff', fn () => array_diff($list, ['a']), fn () => collect($list)->diff(['a'])->all()],
    'array_diff_key' => ['array_diff_key', fn () => array_diff_key($map, ['x' => 0]), fn () => collect($map)->diffKeys(['x' => 0])->all()],
    'array_diff_assoc' => ['array_diff_assoc', fn () => array_diff_assoc($map, ['x' => 3, 'y' => 9]), fn () => collect($map)->diffAssoc(['x' => 3, 'y' => 9])->all()],
    'array_intersect' => ['array_intersect', fn () => array_intersect($list, ['a', 'c']), fn () => collect($list)->intersect(['a', 'c'])->all()],
    'array_intersect_key' => ['array_intersect_key', fn () => array_intersect_key($map, ['y' => 0]), fn () => collect($map)->intersectByKeys(['y' => 0])->all()],
    'array_sum' => ['array_sum', fn () => [array_sum([1, 2, 3]), array_sum([1.5, 2]), array_sum([])], fn () => [collect([1, 2, 3])->sum(), collect([1.5, 2])->sum(), collect([])->sum()]],
    'array_reduce' => ['array_reduce', fn () => array_reduce([1, 2, 3], fn (int $c, int $i): int => $c + $i, 10), fn () => collect([1, 2, 3])->reduce(fn (int $c, int $i): int => $c + $i, 10)],
    'sort' => ['sort', function () use ($list) {
        sort($list);

        return $list;
    }, fn () => collect($list)->sort()->values()->all()],
    'rsort' => ['rsort', function () use ($list) {
        rsort($list);

        return $list;
    }, fn () => collect($list)->sortDesc()->values()->all()],
    'usort' => ['usort', function () use ($list) {
        usort($list, fn (string $a, string $b): int => $b <=> $a);

        return $list;
    }, fn () => collect($list)->sort(fn (string $a, string $b): int => $b <=> $a)->values()->all()],
    'asort' => ['asort', function () use ($map) {
        asort($map);

        return $map;
    }, fn () => collect($map)->sort()->all()],
    'arsort' => ['arsort', function () use ($map) {
        arsort($map);

        return $map;
    }, fn () => collect($map)->sortDesc()->all()],
    'uasort' => ['uasort', function () use ($map) {
        uasort($map, fn (int $a, int $b): int => $b <=> $a);

        return $map;
    }, fn () => collect($map)->sort(fn (int $a, int $b): int => $b <=> $a)->all()],
    'ksort' => ['ksort', function () use ($map) {
        ksort($map);

        return $map;
    }, fn () => collect($map)->sortKeys()->all()],
    'krsort' => ['krsort', function () use ($map) {
        krsort($map);

        return $map;
    }, fn () => collect($map)->sortKeysDesc()->all()],
    'uksort' => ['uksort', function () use ($map) {
        uksort($map, fn (string $a, string $b): int => $b <=> $a);

        return $map;
    }, fn () => collect($map)->sortKeysUsing(fn (string $a, string $b): int => $b <=> $a)->all()],
]);

it('keeps the cases the rule skips genuinely different', function (): void {
    // Empty needle: native true, Str false.
    expect(str_contains('abc', ''))->toBeTrue()->and(Str::contains('abc', ''))->toBeFalse();
    // Native array_unique compares as strings, strict unique does not.
    expect(array_unique([1, '1']))->toHaveCount(1)->and(collect([1, '1'])->unique(strict: true))->toHaveCount(2);
    // Collection slice keeps keys where the native default re-indexes.
    expect(array_slice([1 => 'a', 'k' => 'b', 5 => 'c'], 1))->toBe(['k' => 'b', 0 => 'c'])
        ->and(collect([1 => 'a', 'k' => 'b', 5 => 'c'])->slice(1)->all())->toBe(['k' => 'b', 5 => 'c']);
});
