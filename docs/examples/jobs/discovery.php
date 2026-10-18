<?php

// Classes found as jobs

// app/Jobs/SyncInventory.php
namespace App\Jobs;

class SyncInventory {} // → jobs[SyncInventory].queued false

// app/Actions/ChargeCard.php
namespace App\Actions;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ChargeCard implements ShouldQueue // → jobs[ChargeCard]
{
    use Dispatchable;
}

// app/Actions/VoidCard.php
namespace App\Actions;

use Illuminate\Foundation\Bus\Dispatchable;

class VoidCard // ✗ not recorded
{
    use Dispatchable;
}

// app/Actions/RefundCard.php
namespace App\Actions;

class RefundCard {} // → jobs[RefundCard]

// app/Http/Controllers/PaymentController.php
namespace App\Http\Controllers;

use App\Actions\ChargeCard;
use App\Actions\RefundCard;
use App\Actions\VoidCard;
use Illuminate\Support\Facades\Bus;

class PaymentController
{
    public function store(): void
    {
        ChargeCard::dispatch(); // → jobs[ChargeCard].dispatched_from.method PaymentController::store
        Bus::dispatch(new RefundCard); // → jobs[RefundCard].dispatched_from.method PaymentController::store
        dispatch(new RefundCard); // → jobs[RefundCard].dispatched_from.method PaymentController::store
        VoidCard::dispatch(); // ✗ target_not_indexed
    }
}
