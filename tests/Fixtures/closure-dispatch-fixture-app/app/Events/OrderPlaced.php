<?php

declare(strict_types=1);

namespace App\Events;

class OrderPlaced
{
    public function __construct(public int $orderId = 0)
    {
    }
}
