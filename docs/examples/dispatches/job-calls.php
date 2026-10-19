<?php

// Job dispatch calls and their mode

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

class OrderController
{
    public function store(): void
    {
        dispatch(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        ProcessOrder::dispatch(); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        ProcessOrder::dispatchIf(true); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        ProcessOrder::dispatchUnless(false); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        Bus::dispatch(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.method OrderController::store

        dispatch_sync(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode sync
        ProcessOrder::dispatchSync(); // → jobs[ProcessOrder].dispatched_from.mode sync
        Bus::dispatchSync(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode sync
        Bus::dispatchNow(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode sync

        ProcessOrder::dispatchAfterResponse(); // → jobs[ProcessOrder].dispatched_from.mode after_response
        Bus::dispatchAfterResponse(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode after_response
        dispatch(new ProcessOrder)->afterResponse(); // → jobs[ProcessOrder].dispatched_from.mode after_response

        Queue::push(new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::pushOn('orders', new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::later(60, new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::laterOn('orders', 60, new ProcessOrder); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::bulk([new ProcessOrder]); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::push('App\Jobs\ProcessOrder@handle'); // → jobs[ProcessOrder].dispatched_from.mode push
        Queue::push('App\Support\SyncInventory@fire'); // ✗ class_not_found
        Queue::pushRaw('{"job":"x"}'); // ✗ not recorded
    }
}
