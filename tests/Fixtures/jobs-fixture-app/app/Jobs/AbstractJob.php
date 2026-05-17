<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class AbstractJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}
