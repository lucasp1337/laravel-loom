<?php

// Frequencies Loom cannot turn into cron

// routes/console.php
use Illuminate\Support\Facades\Schedule;

$time = config('reports.time');

Schedule::command('reports:send')->dailyAt($time); // → scheduled_tasks.cron null
Schedule::command('reports:archive')->everyFifteenMinutes()->myMacro(); // → scheduled_tasks.cron null
