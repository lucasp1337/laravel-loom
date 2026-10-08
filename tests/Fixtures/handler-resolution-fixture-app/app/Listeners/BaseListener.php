<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

abstract class BaseListener
{
    public function handle(OrderPlaced $event): void
    {
    }
}
