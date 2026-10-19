<?php

// Events without an event row

// app/Listeners/LogLogin.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Auth\Events\Login;

class LogLogin // ✗ handled_event_not_indexed
{
    public function handle(Login $event): void {} // → listeners[LogLogin].handles.event Illuminate\Auth\Events\Login

    public function handleOrder(OrderPlaced $event): void {} // → events[OrderPlaced].handled_by.listener LogLogin
}
