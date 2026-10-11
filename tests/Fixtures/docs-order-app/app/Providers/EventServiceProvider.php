<?php

namespace App\Providers;

use App\Events\ReceiptLogged;
use App\Events\ReceiptSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ReceiptSent::class, function (ReceiptSent $event): void {
            event(new ReceiptLogged);
        });
    }
}
