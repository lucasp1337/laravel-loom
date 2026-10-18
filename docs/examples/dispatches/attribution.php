<?php

// Which method a dispatch counts for

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class OrderController
{
    public function store(): void
    {
        DB::transaction(fn () => event(new OrderPlaced)); // → events[OrderPlaced].dispatched_from.method OrderController::store

        collect([1, 2])->each(function () {
            ProcessOrder::dispatch(); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        });

        $notify = fn () => event(new OrderPlaced); // → events[OrderPlaced].dispatched_from.method OrderController::store

        Queue::before(fn () => event(new OrderPlaced)); // → events[OrderPlaced].dispatched_from.method OrderController::store

        collect([1, 2])->each($this->notify(...));
    }

    private function notify(): void
    {
        event(new OrderPlaced); // → events[OrderPlaced].dispatched_from.method OrderController::notify
    }
}

// app/helpers.php
event(new \App\Events\OrderPlaced); // ✗ not recorded
