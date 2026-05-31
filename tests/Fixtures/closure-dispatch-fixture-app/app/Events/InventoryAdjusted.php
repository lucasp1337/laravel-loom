<?php

declare(strict_types=1);

namespace App\Events;

class InventoryAdjusted
{
    public function __construct(public int $delta = 0)
    {
    }
}
