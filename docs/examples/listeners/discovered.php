<?php

// Discovered listener, typed to its event

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class SendReceipt // → listeners.registration auto_discovered
{
    public function handle(OrderPlaced $event): void {} // → listeners[SendReceipt].handles.event OrderPlaced
}
