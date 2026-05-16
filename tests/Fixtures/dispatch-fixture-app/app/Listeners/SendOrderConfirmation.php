<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryAdjusted;
use App\Events\OrderConfirmationSent;
use App\Events\OrderPlaced;
use App\Events\SomeEvent;
use App\Jobs\SendReceipt;
use Illuminate\Support\Facades\Event;

class SendOrderConfirmation
{
    public function handle(OrderPlaced $event): void
    {
        event(new OrderConfirmationSent());
        SendReceipt::dispatch();
        Event::dispatch(InventoryAdjusted::class);

        $closure = function () {
            event(new OrderConfirmationSent());
        };
    }

    public function otherMethod(): void
    {
        event(new SomeEvent());
    }
}
