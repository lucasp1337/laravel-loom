<?php

// Dispatches inside a closure listener

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Events\InvoiceSettled;
use App\Events\PaymentReceived;
use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PaymentReceived::class, function ($event) { // → closure_listeners.queued false
            event(new InvoiceSettled); // → closure_listeners[PaymentReceived].dispatches.target InvoiceSettled

            collect([1, 2])->each(fn () => ProcessOrder::dispatch()); // → closure_listeners[PaymentReceived].dispatches.target ProcessOrder
        });
    }
}

// app/Events/PaymentReceived.php
namespace App\Events;

class PaymentReceived {} // → events[PaymentReceived].handled_by []

// app/Events/InvoiceSettled.php
namespace App\Events;

class InvoiceSettled {} // → events[InvoiceSettled].dispatched_from []
