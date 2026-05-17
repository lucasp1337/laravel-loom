<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Billing\Jobs\ChargeCustomer;
use Illuminate\Support\Facades\Bus;

class Billing
{
    public function charge(int $customerId): void
    {
        Bus::chain([
            new \App\Jobs\ProcessOrder,
            new \App\Jobs\SendInvoice,
        ])->dispatch();

        dispatch(new ChargeCustomer());
    }
}
