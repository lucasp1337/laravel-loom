<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\PaymentCaptured;
use App\Events\RefundIssued;
use App\Listeners\RecordPayment;
use App\Listeners\ReverseLedger;
use Illuminate\Contracts\Events\Dispatcher;

class PaymentEventServiceProvider
{
    public function boot(): void
    {
        // Shape B: container resolution gated on the Dispatcher contract.
        app(Dispatcher::class)->listen(PaymentCaptured::class, RecordPayment::class);

        // Shape C: a variable proven to hold the Dispatcher in this scope.
        $dispatcher = app(Dispatcher::class);
        $dispatcher->listen(RefundIssued::class, ReverseLedger::class);
    }
}
