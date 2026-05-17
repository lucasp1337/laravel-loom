<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\StockLow;
use Illuminate\Contracts\Queue\ShouldQueue;

class OrderEventSubscriber implements ShouldQueue
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe($events): array
    {
        return [
            OrderPlaced::class => 'handleOrderPlaced',
            StockLow::class => 'handleStockLow',
        ];
    }

    public function handle(OrderPlaced $event): void {}

    public function handleOrderPlaced(OrderPlaced $event): void {}

    public function handleStockLow(StockLow $event): void {}
}

