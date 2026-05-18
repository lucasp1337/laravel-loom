<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Schedule;

class ScheduleServiceProvider
{
    public function boot(): void
    {
        Schedule::command('horizon:snapshot')
            ->everyFiveMinutes();
    }
}
