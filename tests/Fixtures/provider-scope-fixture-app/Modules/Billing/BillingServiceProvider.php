<?php

namespace Modules\Billing;

use App\Billing\ChargeCustomer;
use App\Events\InvoicePaid;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(InvoicePaid::class, ChargeCustomer::class);
        Invoice::observe(InvoiceObserver::class);
        Schedule::command('invoices:close')->daily();
    }
}
