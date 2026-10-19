<?php

// Facade calls with a variable method name

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\Bus;

class OrderController
{
    public function store(bool $sync, string $method): void
    {
        $call = 'dispatchSync';
        Bus::$call(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode sync
        Bus::{'dispatch'}(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        Bus::{'dispatchIf'}(true, new ProcessOrder); // ✗ not recorded

        Bus::$method(new ProcessOrder); // ✗ dynamic_method_name
        $mode = $sync ? 'dispatchSync' : 'dispatch';
        Bus::$mode(new ProcessOrder); // ✗ dynamic_method_name
    }
}
