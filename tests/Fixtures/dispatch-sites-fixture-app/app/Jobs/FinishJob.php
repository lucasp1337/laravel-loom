<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

final class FinishJob implements ShouldQueue
{
    public function handle(): void {}
}
