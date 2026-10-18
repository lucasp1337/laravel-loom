<?php

// Targets Loom cannot name

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Jobs\ProcessOrder;
use Illuminate\Contracts\Bus\Dispatcher;

class OrderController
{
    private OrderPlaced $event;

    public function store(string $class, string $name, bool $urgent, ?OrderPlaced $placed): void
    {
        $class::dispatch(); // ✗ dynamic_class_name
        event(new $class); // ✗ dynamic_class_name
        event(app(OrderPlaced::class)); // ✗ container_resolution
        event('App\Events\\'.$name); // ✗ string_concatenation
        event($urgent ? new OrderShipped : $placed); // ✗ conditional_dispatch
        event($this->makeEvent()); // ✗ call_result
        event($this->event); // ✗ stored_target
        event($placed ?? new OrderPlaced); // ✗ unsupported_expression
        app(Dispatcher::class)->dispatch(new ProcessOrder); // ✗ not recorded

        event(new OrderPlaced); // → events[OrderPlaced].dispatched_from.method OrderController::store
        event(OrderPlaced::class); // → events[OrderPlaced].dispatched_from.method OrderController::store
        event('App\Events\OrderPlaced'); // ✗ string_event_name
    }

    private function makeEvent(): OrderPlaced
    {
        return new OrderPlaced;
    }
}
