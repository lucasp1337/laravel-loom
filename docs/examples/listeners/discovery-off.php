<?php

// Event discovery turned off

// bootstrap/app.php
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(false)
    ->create();

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class SendReceipt // ✗ not recorded
{
    public function handle(OrderPlaced $event): void {}
}
