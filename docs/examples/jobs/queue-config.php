<?php

// Queue settings from properties

// app/Jobs/BaseJob.php
namespace App\Jobs;

abstract class BaseJob
{
    public $tries = 5;

    public $timeout = 120;
}

// app/Jobs/ExportOrders.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ExportOrders extends BaseJob implements ShouldQueue // → jobs.queue_config.timeout 120
{
    public $connection = 'redis'; // → jobs[ExportOrders].queue_config.connection redis

    public $queue = 'exports'; // → jobs[ExportOrders].queue_config.queue exports

    public $delay = 30; // → jobs[ExportOrders].queue_config.delay 30

    public $tries = 3; // → jobs[ExportOrders].queue_config.tries 3

    public $backoff = 10; // → jobs[ExportOrders].queue_config.backoff 10

    public $afterCommit = false; // → jobs[ExportOrders].queue_config.after_commit false
}

// app/Jobs/ImportOrders.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ImportOrders implements ShouldQueue // → jobs.queue_config.after_commit null
{
    public $queue = self::QUEUE; // → jobs[ImportOrders].queue_config.queue null

    public const QUEUE = 'imports';

    public function backoff(): array // → jobs[ImportOrders].queue_config.backoff null
    {
        return [5];
    }
}
