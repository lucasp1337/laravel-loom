<?php

// Dispatches from a route's action

// routes/web.php
use App\Events\OrderPlaced;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store']);

Route::post('/orders/{order}/ship', function () {
    event(new OrderPlaced); // → routes[/orders/{order}/ship].dispatches.target OrderPlaced → events[OrderPlaced].dispatched_from.method POST /orders/{order}/ship
});

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;

class OrderController
{
    public function store(): void
    {
        ProcessOrder::dispatch(); // → routes[/orders].dispatches.target ProcessOrder
    }
}
