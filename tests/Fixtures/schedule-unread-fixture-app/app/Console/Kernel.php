<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

class Kernel
{
    protected function schedule(Schedule $schedule): void
    {
        $this->external->job(new \stdClass)->hourly();
    }
}
