<?php

namespace Lucasp\Loom\Tests\Rector\NativeFunctionToLaravelHelper\Fixture;

final class SortStatements
{
    /**
     * @param  list<string>  $a
     */
    public function run(array $a): void
    {
        sort($a);
        rsort($a);
        usort($a, fn (string $x, string $y): int => $x <=> $y);
        asort($a);
        arsort($a);
        uasort($a, fn (string $x, string $y): int => $x <=> $y);
        ksort($a);
        krsort($a);
        uksort($a, fn (int $x, int $y): int => $x <=> $y);
    }
}

?>
-----
<?php

namespace Lucasp\Loom\Tests\Rector\NativeFunctionToLaravelHelper\Fixture;

final class SortStatements
{
    /**
     * @param  list<string>  $a
     */
    public function run(array $a): void
    {
        $a = collect($a)->sort()->values()->all();
        $a = collect($a)->sortDesc()->values()->all();
        $a = collect($a)->sort(fn (string $x, string $y): int => $x <=> $y)->values()->all();
        $a = collect($a)->sort()->all();
        $a = collect($a)->sortDesc()->all();
        $a = collect($a)->sort(fn (string $x, string $y): int => $x <=> $y)->all();
        $a = collect($a)->sortKeys()->all();
        $a = collect($a)->sortKeysDesc()->all();
        $a = collect($a)->sortKeysUsing(fn (int $x, int $y): int => $x <=> $y)->all();
    }
}

?>
