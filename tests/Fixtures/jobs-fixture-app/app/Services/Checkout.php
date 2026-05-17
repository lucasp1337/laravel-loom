<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessOrder;

class Checkout
{
    public function finalize(int $orderId): void
    {
        ProcessOrder::dispatch($orderId);
    }
}
