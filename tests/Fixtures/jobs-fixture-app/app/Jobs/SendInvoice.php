<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoice implements ShouldQueue
{
    public string $queue = 'invoices';

    public int $tries = 3;

    public function handle(): void
    {
    }
}
