<?php

namespace App\Listeners;

use App\Events\InventoryAdjusted;
use App\Events\OrderPlaced;

class ReserveStock
{
    public function handle(OrderPlaced $event): void
    {
        event(new InventoryAdjusted);
    }
}
