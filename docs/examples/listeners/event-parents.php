<?php

// Listener typed to a parent event

// app/Events/PaymentEvent.php
namespace App\Events;

abstract class PaymentEvent {} // → events[PaymentEvent].handled_by.listener ReconcileLedger

// app/Events/PaymentFailed.php
namespace App\Events;

class PaymentFailed extends PaymentEvent {} // → events[PaymentFailed].handled_by []

// app/Listeners/ReconcileLedger.php
namespace App\Listeners;

use App\Events\PaymentEvent;

class ReconcileLedger
{
    public function handle(PaymentEvent $event): void {}
}
