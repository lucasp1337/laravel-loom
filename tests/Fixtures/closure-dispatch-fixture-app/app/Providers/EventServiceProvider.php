<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\InventoryAdjusted;
use App\Events\OrderConfirmationSent;
use App\Events\OrderPlaced;
use App\Events\StockLow;
use App\Jobs\SendReceipt;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
{
    public function boot(): void
    {
        // Empty closure: no dispatch site inside its span -> dispatches stays [].
        Event::listen(StockLow::class, function ($e) {
            return null;
        });

        // Single EVENT dispatch via the event() helper.
        Event::listen(OrderPlaced::class, function ($e) {
            event(new OrderConfirmationSent($e->orderId));
        });

        // Single JOB dispatch via the dispatch() helper.
        Event::listen(OrderPlaced::class, function ($e) {
            dispatch(new SendReceipt);
        });

        // Multiple dispatches in one closure body: an event, a job, and a
        // Dispatchable-form site that disambiguates to event (target in events[]).
        // Asserted to come back sorted by (file, line, target).
        Event::listen(InventoryAdjusted::class, function ($e) {
            event(new OrderConfirmationSent($e->delta));
            dispatch(new SendReceipt);
            OrderPlaced::dispatch($e->delta);
        });
    }
}
