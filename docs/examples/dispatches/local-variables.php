<?php

// Targets held in a local variable

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Jobs\ProcessOrder;

class OrderController
{
    public function store(bool $express): void
    {
        $event = new OrderPlaced;
        event($event); // → events[OrderPlaced].dispatched_from.method OrderController::store

        $shipped = OrderShipped::class;
        event($shipped); // → events[OrderShipped].dispatched_from.method OrderController::store

        $next = $express ? new OrderShipped : new OrderPlaced;
        event($next); // → events[OrderShipped].dispatched_from.method OrderController::store → events[OrderPlaced].dispatched_from.method OrderController::store
    }

    public function update(OrderPlaced $event, bool $express): void
    {
        event($event); // ✗ dynamic_class_name

        $job = new ProcessOrder;
        if ($express) {
            $job = new ProcessOrder;
        }
        dispatch($job); // ✗ dynamic_class_name
    }
}
