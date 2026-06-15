<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Nested\InventoryAdjusted;
use App\Events\OrderPlaced;
use App\Jobs\SendReceipt;
use App\Outside\BroadcastOnly;
use App\Outside\CustomEvent;

class Checkout
{
    public function place(int $orderId): void
    {
        // Helper-function form with `new`: discoverable.
        event(new OrderPlaced($orderId));

        // Helper-function form with ::class: discoverable.
        event(InventoryAdjusted::class);

        // Helper-function form against an event outside app/Events/: discoverable,
        // because the helper-function path has no app/Events trim.
        event(new CustomEvent('out-of-tree'));

        // broadcast() helper against an out-of-tree event: discoverable via the
        // same dispatch-site seeding path as event(), no app/Events trim.
        broadcast(new BroadcastOnly('orders'));

        // Dispatchable trait form against a non-event class: must be trimmed
        // out because the resolved file does NOT live under app/Events/.
        SendReceipt::dispatch();
    }
}
