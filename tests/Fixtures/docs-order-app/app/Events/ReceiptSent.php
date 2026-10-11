<?php

namespace App\Events;

class ReceiptSent
{
    public function __construct(public int $orderId) {}
}
