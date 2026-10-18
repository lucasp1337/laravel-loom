<?php

// Event discovery with a pre-configure() bootstrap

// bootstrap/app.php
$app = new Illuminate\Foundation\Application(dirname(__DIR__));

return $app;

// app/Providers/EventServiceProvider.php
namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function shouldDiscoverEvents(): bool
    {
        return true;
    }
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;

class SendReceipt // → listeners.registration auto_discovered
{
    public function handle(OrderPlaced $event): void {}
}
