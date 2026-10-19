<?php

// Listeners that wait for the transaction

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendReceipt implements ShouldHandleEventsAfterCommit // → listeners.after_commit true
{
    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/QueueReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class QueueReceipt implements ShouldQueueAfterCommit // → listeners.after_commit true
{
    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/AuditOrder.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class AuditOrder // → listeners.after_commit true
{
    public $afterCommit = true;

    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/CountOrder.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class CountOrder // → listeners.after_commit false
{
    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/ArchiveOrder.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class ArchiveOrder // → listeners.after_commit true
{
    public function __construct()
    {
        $this->afterCommit = true;
    }

    public function handle(OrderPlaced $event): void {}
}

// app/Listeners/QueueArchive.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueueArchive implements ShouldQueue // → listeners.after_commit false
{
    public function __construct()
    {
        $this->afterCommit = true;
    }

    public function handle(OrderPlaced $event): void {}
}
