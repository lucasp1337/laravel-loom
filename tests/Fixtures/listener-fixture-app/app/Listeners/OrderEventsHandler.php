<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\RestockScheduled;
use App\Events\StockLow;

class OrderEventsHandler
{
    public function handlePlaced(OrderPlaced $event): void {}

    public function handleStockLow(StockLow $event): void {}

    public function handleViaCallable(RestockScheduled $event): void {}

    public function handleViaFcc(RestockScheduled $event): void {}
}
