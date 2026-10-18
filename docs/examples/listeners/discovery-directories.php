<?php

// Event discovery in other directories

// bootstrap/app.php
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: [__DIR__.'/../app/Domain/Orders/Listeners'])
    ->create();

// app/Domain/Orders/Listeners/SendReceipt.php
namespace App\Domain\Orders\Listeners;

use App\Events\OrderPlaced;

class SendReceipt // → listeners.registration auto_discovered
{
    public function handle(OrderPlaced $event): void {}
}
