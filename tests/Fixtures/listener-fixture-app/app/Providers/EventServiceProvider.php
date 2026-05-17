<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\StockLow;
use App\Listeners\AuditSubscriber;
use App\Listeners\NotifyAdmins;
use App\Listeners\OrderEventsHandler;
use App\Listeners\OrderEventSubscriber;
use App\Listeners\PsrOnly;
use App\Listeners\SendOrderConfirmation;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
{
    protected $listen = [
        OrderPlaced::class => [
            SendOrderConfirmation::class,
            [PsrOnly::class, 'someMethod'],
            [OrderEventsHandler::class, 'handlePlaced'],
        ],
        StockLow::class => [
            [OrderEventsHandler::class, 'handleStockLow'],
        ],
    ];

    protected $subscribe = [
        OrderEventSubscriber::class,
    ];

    public function boot(): void
    {
        Event::listen(StockLow::class, NotifyAdmins::class);
        Event::listen($dynamicEvent, \App\Listeners\NeverSeen::class);
        Event::listen(OrderPlaced::class, fn ($e) => null);
        Event::subscribe(AuditSubscriber::class);
    }
}
