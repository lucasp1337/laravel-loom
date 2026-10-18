<?php

// Event discovery switch Loom cannot read

// bootstrap/app.php
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: config('events.paths'))
    ->create();

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class SendReceipt // → listeners.registration discovery_unverified
{
    public function handle(OrderPlaced $event): void {}
}
