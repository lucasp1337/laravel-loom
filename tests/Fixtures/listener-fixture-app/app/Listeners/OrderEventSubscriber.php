<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryDepleted;
use App\Events\OrderPlaced;
use App\Events\StockLow;
use Illuminate\Contracts\Queue\ShouldQueue;

class OrderEventSubscriber implements ShouldQueue
{
    /**
     * @return array<class-string, string|callable>
     */
    public function subscribe($events): array
    {
        return [
            OrderPlaced::class => 'handleOrderPlaced',
            StockLow::class => 'handleStockLow',
            InventoryDepleted::class => fn ($e) => null,
        ];
    }

    public function handle(OrderPlaced $event): void {}

    public function handleOrderPlaced(OrderPlaced $event): void {}

    public function handleStockLow(StockLow $event): void {}
}
