<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\OrderProcessed;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessOrder implements ShouldQueue
{
    public string $connection = 'redis';

    public string $queue = 'high';

    public int $delay = 30;

    public int $tries = 5;

    public int $timeout = 120;

    public int $backoff = 10;

    public function handle(): void
    {
        event(new OrderProcessed());
    }
}
