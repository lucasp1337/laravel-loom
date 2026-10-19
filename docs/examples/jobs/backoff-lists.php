<?php

// Backoff lists

// app/Jobs/SyncInventory.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SyncInventory implements ShouldQueue
{
    public $backoff = [10, 60, 300]; // → jobs[SyncInventory].queue_config.backoff [10,60,300]
}

// app/Jobs/SyncPrices.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SyncPrices implements ShouldQueue
{
    public $backoff = [10, self::MAX]; // → jobs[SyncPrices].queue_config.backoff null

    public const MAX = 600;
}
