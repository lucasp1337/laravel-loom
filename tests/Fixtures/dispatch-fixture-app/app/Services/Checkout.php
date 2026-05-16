<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\OrderPlaced;
use App\Jobs\SendReceipt;

class Checkout
{
    public function finalize(int $orderId): void
    {
        event(new OrderPlaced($orderId));
        event($dynamic);
        event("App\\Events\\" . $name);
        dispatch_sync(new SendReceipt());
    }
}
