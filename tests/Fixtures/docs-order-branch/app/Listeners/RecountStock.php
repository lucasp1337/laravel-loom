<?php

namespace App\Listeners;

use App\Events\InventoryAdjusted;
use App\Events\OrderPlaced;

class RecountStock
{
    public function handle(InventoryAdjusted $event): void
    {
        event(new OrderPlaced(1));
    }
}
