<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderRefunded;

class MultiMethodListener
{
    public function handleRefund(OrderRefunded $event): void
    {
    }

    public function handle(OrderPlaced $event): void
    {
    }

    protected function handleHidden(OrderPlaced $event): void
    {
    }

    public function helper(OrderPlaced $event): void
    {
    }
}
