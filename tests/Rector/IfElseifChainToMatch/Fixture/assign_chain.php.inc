<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class AssignChain
{
    public const KIND_A = 'a';

    public function run(string $kind): string
    {
        if ($kind === self::KIND_A) {
            $label = 'first';
        } elseif ('b' === $kind || $kind === 'c') {
            $label = 'second';
        } elseif ($kind === 'd') {
            $label = 'third';
        } else {
            $label = 'none';
        }

        return $label;
    }
}

?>
-----
<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class AssignChain
{
    public const KIND_A = 'a';

    public function run(string $kind): string
    {
        $label = match ($kind) {
            self::KIND_A => 'first',
            'b', 'c' => 'second',
            'd' => 'third',
            default => 'none',
        };

        return $label;
    }
}

?>
