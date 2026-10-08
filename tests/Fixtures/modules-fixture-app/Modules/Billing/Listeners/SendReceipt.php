<?php

declare(strict_types=1);

namespace Modules\Billing\Listeners;

use Modules\Billing\Events\InvoicePaid;
use Modules\Billing\Jobs\ChargeCard;

class SendReceipt
{
    public function handle(InvoicePaid $event): void
    {
        ChargeCard::dispatch();
    }
}
