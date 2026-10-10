<?php

declare(strict_types=1);

namespace App\Listeners\Concerns;

use App\Events\OrderShipped;

trait HandlesShipped
{
    public function handle(OrderShipped $event): void
    {
    }
}
