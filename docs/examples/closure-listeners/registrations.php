<?php

// Closure registrations

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OrderPlaced::class => [
            fn (OrderPlaced $event) => null, // → closure_listeners.registration listen_array
        ],
        'orders.archived' => [
            function ($event) {}, // → closure_listeners.event orders.archived
        ],
    ];

    public function boot(): void
    {
        Event::listen(OrderPlaced::class, fn ($event) => null); // → closure_listeners.registration event_listen_call
        Event::listen(function (OrderShipped $event) {}); // → closure_listeners.event OrderShipped
        Event::listen(fn (OrderPlaced|OrderShipped $event) => null); // → closure_listeners.event OrderPlaced → closure_listeners.event OrderShipped
        Event::listen(fn ($event) => null); // ✗ untyped_closure_listener
        Event::listen(fn (object $event) => null); // ✗ untyped_closure_listener
        Event::subscribe(\App\Listeners\OrderSubscriber::class);
    }
}

// app/Listeners/OrderSubscriber.php
namespace App\Listeners;

use App\Events\OrderShipped;
use Illuminate\Events\Dispatcher;

class OrderSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderShipped::class => fn (OrderShipped $event) => null, // → closure_listeners.registration subscriber
        ];
    }
}
