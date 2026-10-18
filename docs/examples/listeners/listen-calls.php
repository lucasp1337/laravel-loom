<?php

// Event::listen and the events dispatcher

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Listeners\SendReceipt;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, SendReceipt::class); // → listeners[SendReceipt].registration event_listen_call
        Event::listen([OrderPlaced::class, OrderShipped::class], [SendReceipt::class, 'onAny']); // → listeners[SendReceipt].handles.method onAny
        \Event::listen(OrderShipped::class, [SendReceipt::class, 'onAlias']); // → listeners[SendReceipt].handles.method onAlias
        \Illuminate\Support\Facades\Event::listen(OrderShipped::class, [SendReceipt::class, 'onFqcn']); // → listeners[SendReceipt].handles.method onFqcn
        $this->app['events']->listen(OrderShipped::class, [SendReceipt::class, 'onContainer']); // → listeners[SendReceipt].handles.method onContainer
        app(Dispatcher::class)->listen(OrderShipped::class, [SendReceipt::class, 'onHelper']); // → listeners[SendReceipt].handles.method onHelper
        resolve(Dispatcher::class)->listen(OrderShipped::class, [SendReceipt::class, 'onResolve']); // → listeners[SendReceipt].handles.method onResolve
        app('events')->listen(OrderShipped::class, [SendReceipt::class, 'onAppString']); // ✗ not recorded

        $events = $this->app->make(Dispatcher::class);
        $events->listen(OrderShipped::class, [SendReceipt::class, 'onVariable']); // → listeners[SendReceipt].handles.method onVariable
    }
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

class SendReceipt
{
    public function handle(object $event): void {}

    public function onAny(object $event): void {}

    public function onAlias(object $event): void {}

    public function onFqcn(object $event): void {}

    public function onContainer(object $event): void {}

    public function onHelper(object $event): void {}

    public function onResolve(object $event): void {}

    public function onAppString(object $event): void {}

    public function onVariable(object $event): void {}
}
