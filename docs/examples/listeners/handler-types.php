<?php

// Handler parameter types

// app/Listeners/NotifyWarehouse.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;

class NotifyWarehouse
{
    public function __invoke(?OrderPlaced $event): void {} // → listeners[NotifyWarehouse].handles.method __invoke
}

// app/Listeners/LogOrder.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;

class LogOrder
{
    public function handleOrder(OrderPlaced|OrderShipped $event): void {} // → listeners[LogOrder].handles.event OrderPlaced → listeners[LogOrder].handles.event OrderShipped
}

// app/Listeners/TrackAnything.php
namespace App\Listeners;

class TrackAnything
{
    public function handle($event): void {} // → listeners[TrackAnything].handles []
}

// app/Listeners/CountStrings.php
namespace App\Listeners;

class CountStrings
{
    public function handle(string $event): void {} // → listeners[CountStrings].handles []
}
