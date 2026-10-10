<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Modules\Billing\Events\InvoicePaid;

class InvoiceService
{
    public function pay(): void
    {
        event(new InvoicePaid);
    }

    public function broken(string $name): void
    {
        event($name);
    }
}
