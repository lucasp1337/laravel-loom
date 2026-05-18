<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\SendMail;
use App\Jobs\SendInvoice;
use App\Reports;
use Illuminate\Console\Scheduling\Schedule;

class Kernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('mail:send')
            ->dailyAt('13:00')
            ->timezone('America/Chicago')
            ->withoutOverlapping();

        $schedule->command(SendMail::class)
            ->everyFifteenMinutes();

        $schedule->job(new SendInvoice())
            ->daily();

        $schedule->job(SendInvoice::class)
            ->hourly()
            ->onOneServer();

        $schedule->call(fn () => doSomething())
            ->everyTenMinutes();

        $schedule->call([Reports::class, 'generate'])
            ->weeklyOn(1, '08:00');

        $schedule->call('App\\Maintenance@run')
            ->monthly();

        $schedule->exec('php artisan some:thing')
            ->cron('5 * * * *');

        // Unrecognised frequency helper — cron should be null.
        $schedule->command('cache:clear')
            ->everyBlueMoon();

        // Multiple frequency helpers — last wins (hourly).
        $schedule->command('reports:run')
            ->daily()
            ->hourly();

        // Constraint emission: weekdays + between, with a frequency.
        $schedule->command('inventory:sync')
            ->weekdays()
            ->dailyAt('09:00')
            ->between('08:00', '17:00');

        // runInBackground flag.
        $schedule->command('cleanup:tmp')
            ->everyMinute()
            ->runInBackground();
    }
}
