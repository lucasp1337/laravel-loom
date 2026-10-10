<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class Plain implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void {}
}
