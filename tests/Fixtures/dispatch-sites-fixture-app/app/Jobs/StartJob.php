<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

final class StartJob implements ShouldQueue
{
    public function handle(): void {}
}
