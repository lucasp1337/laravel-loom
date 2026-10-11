<?php

namespace App\Listeners;

use App\Events\ReceiptLogged;

class WriteAuditLog
{
    public function handle(ReceiptLogged $event): void {}
}
