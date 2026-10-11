<?php

namespace App\Listeners;

use App\Events\ReceiptSent;

class ArchiveReceipt
{
    public function handle(ReceiptSent $event): void {}
}
