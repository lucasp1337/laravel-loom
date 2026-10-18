<?php

// Tasks in routes/console.php

// routes/console.php
use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:send')->dailyAt('3:15'); // → scheduled_tasks.kind command → scheduled_tasks.cron 15 3 * * *
Schedule::job(new ProcessOrder)->everyFiveMinutes(); // → scheduled_tasks.target App\Jobs\ProcessOrder → scheduled_tasks.cron */5 * * * *
Schedule::call(fn () => null)->hourly(); // → scheduled_tasks.kind closure → scheduled_tasks.target null
Schedule::exec('node prune.js')->cron('0 4 * * 1'); // → scheduled_tasks.kind exec → scheduled_tasks.cron 0 4 * * 1
Schedule::command('invoices:send')->daysOfMonth(1, 15); // → scheduled_tasks.cron 0 0 1,15 * *
Schedule::command('taxes:file')->quarterlyOn(10, '8:00'); // → scheduled_tasks.cron 0 8 10 1-12/3 *
Schedule::command('queue:work')->everyTenSeconds(); // → scheduled_tasks.cron null → scheduled_tasks.frequency {"every":10,"unit":"seconds"}

Artisan::command('carts:prune {--days=}', fn () => null)->daily(); // → scheduled_tasks.target carts:prune
