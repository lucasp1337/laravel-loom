<?php

declare(strict_types=1);

namespace App;

class Grand
{
    public function shared(): string
    {
        return 'grand';
    }

    private function secret(): void
    {
    }

    public function viaParent(self $e): void
    {
    }
}

trait Loud
{
    public function shared(): string
    {
        return 'trait';
    }

    abstract public function mustHave(): void;

    public function viaTrait(self $e): void
    {
    }
}

class Parented extends Grand
{
    use Loud;

    public function mustHave(): void
    {
    }
}

class Overriding extends Grand
{
    use Loud;

    public function shared(): string
    {
        return 'own';
    }

    public function mustHave(): void
    {
    }
}

class Hidden extends Grand
{
    use Loud {
        shared as protected;
        viaTrait as public renamed;
    }

    public function mustHave(): void
    {
    }
}

class CycleA extends CycleB
{
}

class CycleB extends CycleA
{
}
