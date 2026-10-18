<?php

// Registrations Loom cannot read

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\SendReceipt;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function boot(Dispatcher $events, string $name, object $listener, object $subscriber): void
    {
        Event::listen($name, SendReceipt::class); // ✗ unreadable_event_name
        Event::listen(OrderPlaced::class, $listener); // ✗ unreadable_listener
        Event::listen(OrderPlaced::class, [$this, 'handle']); // ✗ unreadable_listener
        Event::listen(OrderPlaced::class, 'App\Listeners\SendReceipt::handle'); // ✗ unreadable_listener
        Event::subscribe($subscriber); // ✗ unreadable_subscriber

        $events->listen(OrderPlaced::class, [SendReceipt::class, 'fromParameter']); // ✗ not recorded
        $this->dispatcher->listen(OrderPlaced::class, [SendReceipt::class, 'fromProperty']); // ✗ not recorded
    }
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

class SendReceipt
{
    public function fromParameter(object $event): void {}

    public function fromProperty(object $event): void {}
}
