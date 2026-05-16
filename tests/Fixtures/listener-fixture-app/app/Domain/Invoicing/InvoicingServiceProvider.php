<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Invoicing\Listeners\IssueInvoice;
use App\Events\OrderPlaced;
use Illuminate\Support\Facades\Event;

class InvoicingServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, IssueInvoice::class);
    }
}
