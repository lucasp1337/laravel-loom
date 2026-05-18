<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('queue:prune-batches')
            ->daily();

        $schedule->job(\App\Jobs\SendInvoice::class)
            ->everyFiveMinutes();
    });
