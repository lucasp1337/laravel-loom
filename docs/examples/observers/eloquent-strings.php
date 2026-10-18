<?php

// eloquent.* event strings

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Listeners\AuditOrder;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        'eloquent.deleted: App\Models\Order' => [
            AuditOrder::class, // → model_events[eloquent.deleted: App\Models\Order].handled_by.handler AuditOrder
        ],
    ];

    public function boot(): void
    {
        Event::listen('eloquent.created: App\Models\Order', 'App\Listeners\AuditOrder@created'); // → model_events[eloquent.created: App\Models\Order].handled_by.method created
        Event::listen('eloquent.updated: App\Models\Order', [AuditOrder::class, 'updated']); // → model_events[eloquent.updated: App\Models\Order].handled_by.method updated
        Event::listen('eloquent.booted: App\Models\Order', AuditOrder::class); // → model_events[eloquent.booted: App\Models\Order].handled_by.handler AuditOrder
        Event::listen('eloquent.saved: App\Models\Order', fn ($order) => null); // → model_events[eloquent.saved: App\Models\Order].handled_by.method {closure}
        $this->app['events']->listen('eloquent.restored: App\Models\Order', AuditOrder::class); // ✗ not recorded
    }
}

// app/Listeners/AuditOrder.php
namespace App\Listeners;

class AuditOrder
{
    public function handle(): void {}

    public function created(): void {}

    public function updated(): void {}
}
