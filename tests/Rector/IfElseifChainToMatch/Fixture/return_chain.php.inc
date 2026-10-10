<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class ReturnChain
{
    private ?string $mode = null;

    public function run(int $n): string
    {
        if ($n === 1) {
            return 'one';
        } elseif ($n === 2) {
            return 'two';
        } else {
            return 'many';
        }
    }

    public function property(): string
    {
        if ($this->mode === null) {
            return 'unset';
        } elseif ($this->mode === 'fast') {
            return 'f';
        } else {
            return 'other';
        }
    }
}

?>
-----
<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class ReturnChain
{
    private ?string $mode = null;

    public function run(int $n): string
    {
        return match ($n) {
            1 => 'one',
            2 => 'two',
            default => 'many',
        };
    }

    public function property(): string
    {
        return match ($this->mode) {
            null => 'unset',
            'fast' => 'f',
            default => 'other',
        };
    }
}

?>
