<?php

// Queue settings from the constructor

// app/Jobs/BaseJob.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;

abstract class BaseJob
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection('sqs');
    }
}

// app/Jobs/ShipOrder.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ShipOrder extends BaseJob implements ShouldQueue
{
    public $queue = 'default';

    public function __construct(bool $urgent = false)
    {
        parent::__construct(); // → jobs[ShipOrder].queue_config.connection sqs
        $this->onQueue('shipping'); // → jobs[ShipOrder].queue_config.queue shipping
        $this->delay(60); // → jobs[ShipOrder].queue_config.delay 60
        $this->afterCommit(); // → jobs[ShipOrder].queue_config.after_commit true

        if ($urgent) {
            $this->onQueue('urgent');
        }
    }
}

// app/Jobs/RestockOrder.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class RestockOrder implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct()
    {
        $this->queue = 'restock'; // → jobs[RestockOrder].queue_config.queue restock
        $this->beforeCommit(); // → jobs[RestockOrder].queue_config.after_commit false
    }
}
