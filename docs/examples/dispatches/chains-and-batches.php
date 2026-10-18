<?php

// Bus::chain and Bus::batch

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Jobs\ChargeOrder;
use App\Jobs\ProcessOrder;
use App\Jobs\ShipOrder;
use Illuminate\Support\Facades\Bus;

class OrderController
{
    public function store(array $jobs): void
    {
        Bus::chain([new ProcessOrder, new ChargeOrder])->dispatch(); // → jobs[ProcessOrder].dispatched_from.method OrderController::store → jobs[ChargeOrder].dispatched_from.method OrderController::store
        Bus::batch([[new ChargeOrder, new ShipOrder]])->dispatch(); // → jobs[ShipOrder].dispatched_from.method OrderController::store

        $steps = [new ShipOrder];
        Bus::chain($steps)->dispatch(); // → jobs[ShipOrder].dispatched_from.method OrderController::store

        Bus::chain($jobs)->dispatch(); // ✗ dynamic_list
        Bus::chain([new ChargeOrder, ...$jobs])->dispatch(); // ✗ dynamic_list → jobs[ChargeOrder].dispatched_from.method OrderController::store
    }
}

// app/Jobs/ChargeOrder.php
namespace App\Jobs;

class ChargeOrder {}

// app/Jobs/ShipOrder.php
namespace App\Jobs;

class ShipOrder {}
