<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\Prune;
use Illuminate\Console\Scheduling\Schedule;

class Kernel
{
    private Schedule $scheduleInstance;

    protected function schedule(Schedule $schedule): void
    {
        $this->scheduleInstance = $schedule;

        $this->registerReports($schedule);

        $this->scheduleInstance->job(new Prune)->hourly();

        $schedule->command('report')->weekly()->sundays()->at('03:15');

        if (config('alerts.enabled')) {
            $schedule->command('alerts')->daily();
        } elseif (config('alerts.legacy')) {
            $schedule->command('alerts:legacy')->daily();
        } else {
            $schedule->command('alerts:off')->daily();
        }
    }

    private function registerReports(Schedule $reports): void
    {
        $reports->command('reports:daily')->daily();
    }
}
