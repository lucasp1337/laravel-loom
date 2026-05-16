<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLow;

class NotifyAdmins
{
    public function handle(StockLow $event): void
    {
    }
}
