<?php

namespace App\Listeners;

use App\Actions\StartLogDrain;
use App\Events\OrderPaid;
use App\Jobs\FinishJob;
use App\Jobs\RecomputeTotals;
use App\Jobs\StartJob;
use App\Notifications\ShippedNotice;
use Illuminate\Support\Facades\Bus;

class OnOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        $this->refresh();
        $event->user?->notify(new ShippedNotice);
        Bus::batch([[new StartJob, new FinishJob], new RecomputeTotals])->dispatch();
        StartLogDrain::dispatch();
    }

    private function refresh(): void
    {
        $this->recompute();
    }

    private function recompute(): void
    {
        RecomputeTotals::dispatch();
    }
}
