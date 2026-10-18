<?php

// Groups, conditions and constraints

// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::daily()->onOneServer()->group(function () {
    Schedule::command('reports:send'); // → scheduled_tasks.cron 0 0 * * * → scheduled_tasks.on_one_server true
    Schedule::command('reports:archive')->hourly(); // → scheduled_tasks.cron 0 * * * *
});

if (app()->isProduction()) {
    Schedule::command('backup:run')->daily()->weekdays()->between('1:00', '5:00')->withoutOverlapping(); // → scheduled_tasks.constraints if(\app()->isProduction()) → scheduled_tasks.constraints weekdays → scheduled_tasks.without_overlapping true
}

Schedule::command('cache:warm')->hourly()->environments('production')->when(fn () => true); // → scheduled_tasks.constraints when(closure)
Schedule::command('queue:prune')->hourly()->timezone('Europe/Paris')->runInBackground(); // → scheduled_tasks.timezone Europe/Paris → scheduled_tasks.run_in_background true
