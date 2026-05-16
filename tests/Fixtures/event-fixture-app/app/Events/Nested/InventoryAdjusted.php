<?php

declare(strict_types=1);

namespace App\Events\Nested;

class InventoryAdjusted
{
    public function __construct(public int $sku = 0)
    {
    }
}
