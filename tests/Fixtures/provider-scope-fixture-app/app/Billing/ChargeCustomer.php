<?php

namespace App\Billing;

use App\Events\InvoicePaid;

class ChargeCustomer
{
    public function handle(InvoicePaid $event): void {}
}
