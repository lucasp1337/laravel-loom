<?php

declare(strict_types=1);

use App\Console\Commands\SendMail;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SendMail::class)->dailyAt('03:30');

Schedule::call(function () {
    // inline work
})->hourly()->name('console-routes-closure');

Schedule::command('console-routes:prune')->weekly()->onOneServer();
