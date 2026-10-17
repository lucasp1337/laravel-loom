<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RecomputeTotals implements ShouldQueue
{
    public function handle(): void
    {
        if (true) {
            self::dispatch();
        }
    }
}
