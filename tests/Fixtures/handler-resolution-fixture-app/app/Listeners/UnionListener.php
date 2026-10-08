<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;

class UnionListener
{
    public function handle(OrderPlaced|OrderShipped|string $event): void
    {
    }
}
