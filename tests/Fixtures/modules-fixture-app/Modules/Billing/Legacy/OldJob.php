<?php

declare(strict_types=1);

namespace Modules\Billing\Legacy;

use Modules\Billing\Events\InvoicePaid;

class OldJob
{
    public function run(): void
    {
        event(new InvoicePaid);
    }
}
