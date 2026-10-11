<?php

namespace App\Events;

class OrderPlaced
{
    public function __construct(public int $orderId) {}
}
