<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\OrderReceipt;
use App\Notifications\OrderNotice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendReceipt
{
    public function handle(OrderPlaced $event): void
    {
        Mail::to($event->email)->send(new OrderReceipt);
        Notification::send($event, new OrderNotice);
    }
}
