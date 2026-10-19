<?php

// The $observers array

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\OrderObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $observers = [
        Order::class => [OrderObserver::class, AuditObserver::class], // → observers[OrderObserver].registration observers_array → observers[AuditObserver].observes App\Models\Order
        'App\Models\User' => AuditObserver::class, // → observers[AuditObserver].observes App\Models\User
    ];
}

// app/Observers/OrderObserver.php
namespace App\Observers;

class OrderObserver // → model_events[eloquent.created: App\Models\Order].handled_by.handler OrderObserver
{
    public function created(): void {}
}

// app/Observers/AuditObserver.php
namespace App\Observers;

class AuditObserver
{
    public function saved(): void {}
}
