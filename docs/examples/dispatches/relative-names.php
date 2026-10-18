<?php

// self and static

// app/Jobs/BaseSync.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

abstract class BaseSync implements ShouldQueue
{
    use Dispatchable;
}

// app/Jobs/SyncStock.php
namespace App\Jobs;

class SyncStock extends BaseSync
{
    public function handle(): void
    {
        self::dispatch(); // → jobs[SyncStock].dispatched_from.method SyncStock::handle
        static::dispatch(); // → jobs[SyncStock].dispatched_from.method SyncStock::handle
        dispatch(new self); // → jobs[SyncStock].dispatched_from.method SyncStock::handle
    }
}

// app/Jobs/Concerns/Retries.php
namespace App\Jobs\Concerns;

trait Retries
{
    public function retry(): void
    {
        self::dispatch(); // ✗ relative_class_name
    }
}
