<?php

// Console kernel and withSchedule

// app/Console/Kernel.php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('reports:send')->weekly()->at('9:30'); // → scheduled_tasks.cron 30 9 * * 0
        $this->billing($schedule);
    }

    private function billing(Schedule $schedule): void
    {
        $schedule->command('invoices:charge')->monthlyOn(1, '6:00'); // → scheduled_tasks.cron 0 6 1 * *
    }
}

// bootstrap/app.php
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('carts:prune')->daily(); // → scheduled_tasks.cron 0 0 * * *
    })
    ->create();
