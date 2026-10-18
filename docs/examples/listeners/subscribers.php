<?php

// Subscribers

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Listeners\OrderSubscriber;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $subscribe = [
        OrderSubscriber::class, // → listeners[OrderSubscriber].registration subscriber
    ];
}

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Listeners\ShippingSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(ShippingSubscriber::class); // → listeners[ShippingSubscriber].registration subscriber
    }
}

// app/Listeners/OrderSubscriber.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use Illuminate\Events\Dispatcher;

class OrderSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'onPlaced', // → listeners[OrderSubscriber].handles.method onPlaced
        ];
    }

    public function onPlaced(OrderPlaced $event): void {}
}

// app/Listeners/ShippingSubscriber.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use Illuminate\Events\Dispatcher;

class ShippingSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OrderShipped::class, [self::class, 'onShipped']); // → listeners[ShippingSubscriber].handles.method onShipped

        collect([OrderPlaced::class])->each(fn ($event) => $events->listen($event, [self::class, 'onAny'])); // ✗ not recorded
    }

    public function onShipped(OrderShipped $event): void {}

    public function onAny(object $event): void {}
}
