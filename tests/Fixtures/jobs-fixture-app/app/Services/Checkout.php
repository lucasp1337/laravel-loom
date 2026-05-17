<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CartCleared;
use App\Jobs\ProcessOrder;

class Checkout
{
    public function finalize(int $orderId): void
    {
        ProcessOrder::dispatch($orderId);

        // Ambiguous Dispatchable-form dispatch where the target is an EVENT,
        // not a job. JobsScanner must not seed CartCleared into jobs[].
        CartCleared::dispatch();
    }
}
