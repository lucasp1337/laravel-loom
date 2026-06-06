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

        // weeklyOn with an array of weekday integers.
        $schedule->command('digest:weekly')
            ->weeklyOn([1, 3, 5], '08:00');

        // Unknown trailing modifier after a recognised frequency — must
        // null the cron, mirroring runtime "last cron expression set".
        $schedule->command('macro:sometime')
            ->daily()
            ->someUserMacro();

        // everyOddHour with no args -> "0 1-23/2 * * *".
        $schedule->command('odd:default')
            ->everyOddHour();

        // everyOddHour with an explicit minute -> "30 1-23/2 * * *".
        $schedule->command('odd:atminute')
            ->everyOddHour(30);

        // quarterlyOn with no args -> "0 0 1 1-12/3 *".
        $schedule->command('quarter:default')
            ->quarterlyOn();

        // quarterlyOn with day + time args -> "30 14 15 1-12/3 *".
        $schedule->command('quarter:args')
            ->quarterlyOn(15, '14:30');

        // quarterlyOn with an unparseable time arg -> cron null.
        $schedule->command('quarter:dynamic')
            ->quarterlyOn(1, 'not-a-time');

        // Multi-hour helper with a minute arg -> "15 */2 * * *".
        $schedule->command('twohours:atminute')
            ->everyTwoHours(15);

        // Multi-hour helper, no arg regression guard -> "0 */2 * * *".
        $schedule->command('twohours:default')
            ->everyTwoHours();

        // Six-hour helper with a minute arg -> "45 */6 * * *".
        $schedule->command('sixhours:atminute')
            ->everySixHours(45);

        // days([...]) is an opaque constraint, kept alongside the cron.
        $schedule->command('days:array')
            ->daily()
            ->days([1, 5]);

        // days(...) variadic ints -> constraint "days(1,5)".
        $schedule->command('days:variadic')
            ->daily()
            ->days(1, 5);

        // days() with an unresolvable arg -> constraint "days(?)".
        $schedule->command('days:dynamic')
            ->daily()
            ->days($pick);
    }

    /**
     * Sibling helper outside `schedule()`. Chains here must NOT emit as
     * scheduled entries — the trusted-scope check narrows kernel-form
     * discovery to the schedule method body.
     */
    protected function helper(Schedule $schedule): void
    {
        $schedule->command('decoy:command')
            ->daily();
    }
}
