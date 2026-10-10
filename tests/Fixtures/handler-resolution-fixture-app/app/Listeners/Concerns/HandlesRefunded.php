<?php

declare(strict_types=1);

namespace App\Listeners\Concerns;

use App\Events\OrderRefunded;

trait HandlesRefunded
{
    public function handle(OrderRefunded $event): void
    {
    }
}
