<?php

// Invokable listeners

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Listeners\NotifyWarehouse;
use App\Listeners\SendReceipt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, NotifyWarehouse::class); // → listeners[NotifyWarehouse].handles.method __invoke
        Event::listen(OrderShipped::class, [NotifyWarehouse::class, 'onShipped']); // → events[OrderShipped].handled_by.method __invoke
        Event::listen(OrderShipped::class, SendReceipt::class); // → listeners[SendReceipt].handles.method handle
    }
}

// app/Listeners/NotifyWarehouse.php
namespace App\Listeners;

class NotifyWarehouse
{
    public function __invoke(object $event): void {}
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

class SendReceipt
{
    public function handle(object $event): void {}

    public function __invoke(object $event): void {}
}
