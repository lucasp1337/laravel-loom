<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class PlainIfElse
{
    public function run(string $k): string
    {
        if ($k === 'a') {
            return 'x';
        } else {
            return 'y';
        }
    }
}
