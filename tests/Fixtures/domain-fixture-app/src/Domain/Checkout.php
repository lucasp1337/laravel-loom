<?php

declare(strict_types=1);

namespace Acme\Shop;

use Acme\Shop\Events\OrderPlaced;
use Acme\Shop\Jobs\ShipOrder;

class Checkout
{
    public function place(): void
    {
        event(new OrderPlaced);
        ShipOrder::dispatch();
    }
}
