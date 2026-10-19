<?php

namespace App\Billing;

use App\Events\OrderShipped;

class NotifyWarehouse
{
    public function handle(OrderShipped $event): void {}
}
