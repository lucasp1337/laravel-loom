<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\ReceiptSent;
use App\Jobs\SendMail;
use App\Mail\OrderReceipt;
use Illuminate\Support\Facades\Mail;

class SendReceipt
{
    public function handle(OrderPlaced $event): void
    {
        event(new ReceiptSent($event->orderId));
        SendMail::dispatch($event->orderId);
        Mail::to('orders@example.com')->send(new OrderReceipt($event->orderId));
    }
}
