<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryDepleted;
use App\Events\OrderPlaced;
use App\Events\StockLow;

class ImperativeSubscriber
{
    public function subscribe($events): void
    {
        $events->listen(OrderPlaced::class, [self::class, 'onPlaced']);
        $events->listen(StockLow::class, 'onStockLow');
        $events->listen(InventoryDepleted::class, [NotifyAdmins::class, 'handle']);
        $events->listen(OrderPlaced::class, fn ($e) => null);
    }

    public function onPlaced(OrderPlaced $event): void {}

    public function onStockLow(StockLow $event): void {}
}
