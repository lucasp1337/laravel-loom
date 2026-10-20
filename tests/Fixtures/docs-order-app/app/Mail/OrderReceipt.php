<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OrderReceipt extends Mailable
{
    public function __construct(public int $orderId) {}
}
