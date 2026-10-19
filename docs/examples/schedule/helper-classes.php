<?php

// Schedule handed to another class

// app/Console/Kernel.php
namespace App\Console;

use App\Scheduling\BillingTasks;
use App\Scheduling\CleanupTasks;
use App\Scheduling\ReportTasks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected ReportTasks $reports;

    protected function schedule(Schedule $schedule): void
    {
        (new BillingTasks)->register($schedule);
        BillingTasks::nightly($schedule);

        (new CleanupTasks)->register($schedule);

        if (app()->isProduction()) {
            app(CleanupTasks::class)->register($schedule);
        }

        if (config('cleanup.staging')) {
            resolve(CleanupTasks::class)->register($schedule);
        }

        $this->reports->register($schedule); // ✗ not recorded
    }
}

// app/Scheduling/BillingTasks.php
namespace App\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

class BillingTasks
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('invoices:charge')->daily(); // → scheduled_tasks.cron 0 0 * * *
        $this->reminders($schedule);
    }

    public static function nightly(Schedule $schedule): void
    {
        $schedule->command('invoices:close')->dailyAt('23:00'); // → scheduled_tasks.cron 0 23 * * *
    }

    private function reminders(Schedule $schedule): void
    {
        $schedule->command('invoices:remind')->hourly(); // → scheduled_tasks.cron 0 * * * *
    }
}

// app/Scheduling/CleanupTasks.php
namespace App\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

class CleanupTasks
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('logs:prune')->weekly(); // → scheduled_tasks.constraints [] → scheduled_tasks.constraints ["if(app()->isProduction())"] → scheduled_tasks.constraints ["if(config('cleanup.staging'))"]
    }
}

// app/Scheduling/ReportTasks.php
namespace App\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

class ReportTasks
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('reports:send')->daily(); // ✗ not recorded
    }
}

// app/Scheduling/QueueTasks.php
namespace App\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

class QueueTasks
{
    public function __invoke(Schedule $schedule): void
    {
        $schedule->job(new \App\Jobs\ProcessOrder)->everyFiveMinutes(); // → scheduled_tasks.cron */5 * * * *
    }
}

// bootstrap/app.php
use App\Scheduling\QueueTasks;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(new QueueTasks)
    ->create();
