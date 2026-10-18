<?php

// Events fired with X::dispatch()

// app/Events/OrderCancelled.php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrderCancelled // → events[OrderCancelled]
{
    use Dispatchable;
}

// app/Support/StockLow.php
namespace App\Support;

use Illuminate\Foundation\Events\Dispatchable;

class StockLow // ✗ not recorded
{
    use Dispatchable;
}

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderCancelled;
use App\Support\StockLow;

class OrderController
{
    public function cancel(): void
    {
        OrderCancelled::dispatch(); // → events[OrderCancelled].dispatched_from.method OrderController::cancel
        OrderCancelled::dispatchIf(true); // → events[OrderCancelled].dispatched_from.method OrderController::cancel
        OrderCancelled::dispatchUnless(false); // → events[OrderCancelled].dispatched_from.method OrderController::cancel
        StockLow::dispatch(); // ✗ target_not_indexed
    }
}
