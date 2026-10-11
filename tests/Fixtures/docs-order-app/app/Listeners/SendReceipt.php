<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\ReceiptSent;
use App\Jobs\SendMail;

class SendReceipt
{
    public function handle(OrderPlaced $event): void
    {
        event(new ReceiptSent($event->orderId));
        SendMail::dispatch($event->orderId);
    }
}
