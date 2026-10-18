<?php

// Dispatches reached through helper methods

// app/Listeners/BaseListener.php
namespace App\Listeners;

use App\Events\OrderShipped;

abstract class BaseListener
{
    protected function shipped(): void
    {
        event(new OrderShipped); // → events[OrderShipped].dispatched_from.method BaseListener::shipped
    }
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Jobs\ProcessOrder;
use App\Support\Receipts;

class SendReceipt extends BaseListener
{
    public function __construct(private Receipts $receipts) {}

    public function handle(OrderPlaced $event): void
    {
        ProcessOrder::dispatch(); // → listeners[SendReceipt].dispatches.confidence high
        $this->issue();
        $this->shipped();
        $this->receipts->send();
    }

    private function issue(): void
    {
        self::log();
    }

    private static function log(): void
    {
        ProcessOrder::dispatch(); // → listeners[SendReceipt].dispatches.via ["issue","log"] → listeners[SendReceipt].dispatches.confidence medium
    }
}

// app/Support/Receipts.php
namespace App\Support;

use App\Jobs\ProcessOrder;

class Receipts
{
    public function send(): void
    {
        ProcessOrder::dispatch(); // → jobs[ProcessOrder].dispatched_from.method Receipts::send
    }
}
