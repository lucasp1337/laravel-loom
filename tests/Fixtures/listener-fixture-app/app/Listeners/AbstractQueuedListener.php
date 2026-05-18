<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class AbstractQueuedListener implements ShouldQueue
{
}
