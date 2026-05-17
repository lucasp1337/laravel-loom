<?php

declare(strict_types=1);

namespace App\Domain\Billing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ChargeCustomer implements ShouldQueue
{
    public string $connection = 'sqs';

    public string $queue = 'billing';

    public function handle(): void
    {
    }
}
