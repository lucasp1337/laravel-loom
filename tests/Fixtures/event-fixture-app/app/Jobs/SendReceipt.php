<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * A job-shaped class that lives outside app/Events/. It is dispatched via the
 * Dispatchable pattern (X::dispatch(...)) from Checkout, and EventScanner must
 * filter it out per the §3b tightening rule.
 */
class SendReceipt
{
    public function handle(): void
    {
    }
}
