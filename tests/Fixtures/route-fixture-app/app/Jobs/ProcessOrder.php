<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessOrder implements ShouldQueue
{
    public function handle(): void
    {
    }
}
