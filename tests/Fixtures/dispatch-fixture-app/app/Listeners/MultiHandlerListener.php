<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryAdjusted;
use App\Events\OrderPlaced;
use App\Jobs\SendReceipt;

/**
 * Registered via the $listen array with explicit method tuples so each
 * handler method is part of the listener's handles[*].method set. The
 * Phase 3 dispatch attribution must attribute dispatches inside
 * handlePlaced() to this listener even though no handle() method exists.
 */
class MultiHandlerListener
{
    public function handlePlaced(OrderPlaced $event): void
    {
        SendReceipt::dispatch();
    }

    public function handleAdjusted(InventoryAdjusted $event): void
    {
        // Intentionally a different target so we can assert the dispatch
        // gets attributed even from a non-handle() method body.
        event(new \App\Events\OrderConfirmationSent);
    }
}
