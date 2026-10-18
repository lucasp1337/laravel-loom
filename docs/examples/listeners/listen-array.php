<?php

// The $listen array

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Listeners\AuditOrders;
use App\Listeners\SendReceipt;
use Closure;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OrderPlaced::class => [
            SendReceipt::class, // → listeners[SendReceipt].registration listen_array
            [AuditOrders::class, 'onPlaced'], // → listeners[AuditOrders].handles.method onPlaced
            'App\Listeners\AuditOrders@record', // → listeners[AuditOrders].handles.method record
        ],
        OrderShipped::class => [
            AuditOrders::class.'@onShipped', // → listeners[AuditOrders].handles.method onShipped
            AuditOrders::onArchived(...), // → listeners[AuditOrders].handles.method onArchived
            Closure::fromCallable([AuditOrders::class, 'onRefunded']), // → listeners[AuditOrders].handles.method onRefunded
        ],
        'orders.*' => [
            SendReceipt::class, // → listeners[SendReceipt].handles.event orders.*
        ],
    ];
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

class SendReceipt
{
    public function handle(object $event): void {}
}

// app/Listeners/AuditOrders.php
namespace App\Listeners;

class AuditOrders
{
    public function onPlaced(object $event): void {}

    public function record(object $event): void {}

    public function onShipped(object $event): void {}

    public static function onArchived(object $event): void {}

    public function onRefunded(object $event): void {}
}
