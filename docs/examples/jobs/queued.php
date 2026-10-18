<?php

// Queued through interfaces and parents

// app/Jobs/SendInvoice.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoice implements ShouldQueue {} // → jobs.queued true

// app/Jobs/QueuedJob.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

interface QueuedJob extends ShouldQueueAfterCommit {}

// app/Jobs/BaseJob.php
namespace App\Jobs;

abstract class BaseJob implements QueuedJob {}

// app/Jobs/ArchiveOrders.php
namespace App\Jobs;

class ArchiveOrders extends BaseJob {} // → jobs.queued true → jobs.queue_config.after_commit true

// app/Jobs/PruneCarts.php
namespace App\Jobs;

use Illuminate\Queue\CallQueuedClosure;

class PruneCarts extends CallQueuedClosure {} // → jobs.queued false
