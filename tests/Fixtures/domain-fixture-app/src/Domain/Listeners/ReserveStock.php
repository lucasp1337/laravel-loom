<?php

declare(strict_types=1);

namespace Acme\Shop\Listeners;

use Acme\Shop\Events\OrderPlaced;

class ReserveStock
{
    public function handle(OrderPlaced $event): void
    {
    }
}
