<?php

namespace Lucasp\Loom\Tests\Rector\IfElseifChainToMatch\Fixture;

final class SkipShapes
{
    public function looseComparison(string $k): string
    {
        if ($k == 'a') {
            return 'x';
        } elseif ($k == 'b') {
            return 'y';
        } else {
            return 'z';
        }
    }

    public function noElse(string $k): string
    {
        $out = '';
        if ($k === 'a') {
            $out = 'x';
        } elseif ($k === 'b') {
            $out = 'y';
        }

        return $out;
    }

    public function differentSubjects(string $a, string $b): string
    {
        if ($a === 'x') {
            return '1';
        } elseif ($b === 'y') {
            return '2';
        } else {
            return '3';
        }
    }

    public function sideEffectSubject(): string
    {
        if ($this->next() === 'a') {
            return '1';
        } elseif ($this->next() === 'b') {
            return '2';
        } else {
            return '3';
        }
    }

    public function mixedBranches(string $k): ?string
    {
        $out = null;
        if ($k === 'a') {
            return 'x';
        } elseif ($k === 'b') {
            $out = 'y';
        } else {
            $out = 'z';
        }

        return $out;
    }

    public function differentTargets(string $k): array
    {
        if ($k === 'a') {
            $one = 1;
        } elseif ($k === 'b') {
            $two = 2;
        } else {
            $one = 0;
        }

        return [$one ?? null, $two ?? null];
    }

    public function multiStatementBranch(string $k): string
    {
        if ($k === 'a') {
            $this->touch();

            return 'x';
        } elseif ($k === 'b') {
            return 'y';
        } else {
            return 'z';
        }
    }

    public function repeatedLiteral(string $k): string
    {
        if ($k === 'a') {
            return 'x';
        } elseif ($k === 'a') {
            return 'y';
        } else {
            return 'z';
        }
    }

    public function commentedBranch(string $k): string
    {
        if ($k === 'a') {
            // the first case
            return 'x';
        } elseif ($k === 'b') {
            return 'y';
        } else {
            return 'z';
        }
    }

    public function nonLiteral(string $k, string $other): string
    {
        if ($k === $other) {
            return 'x';
        } elseif ($k === 'b') {
            return 'y';
        } else {
            return 'z';
        }
    }

    private function next(): string
    {
        return 'a';
    }

    private function touch(): void {}
}
