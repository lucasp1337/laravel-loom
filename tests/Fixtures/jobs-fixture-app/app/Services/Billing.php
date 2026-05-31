<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Billing\Jobs\ChargeCustomer;
use Illuminate\Support\Facades\Bus;

class Billing
{
    public function charge(int $customerId): void
    {
        Bus::chain([
            new \App\Jobs\ProcessOrder,
            new \App\Jobs\SendInvoice,
        ])->dispatch();

        dispatch(new ChargeCustomer());
    }

    public function chargeWithDelay(int $orderId): void
    {
        // Chain-wrapped dispatch target (issue #31): the dispatch() helper's
        // argument is a fluent chain `(new ProcessOrder())->delay(60)`. The
        // leading MethodCall chain must be unwrapped so this lands in
        // jobs[ProcessOrder].dispatched_from[], NOT unresolved_dispatches[].
        dispatch((new \App\Jobs\ProcessOrder())->delay(60));
    }

    public function chargeWithOuterChain(int $orderId): void
    {
        // (c) outer PendingDispatch chain (issue #32): modifiers sit on the
        // PendingDispatch returned by Job::dispatch(...), not on the argument.
        // Expect overrides {connection, queue, delay} in dispatched_from[].
        \App\Jobs\ProcessOrder::dispatch($orderId)
            ->onQueue('high')
            ->onConnection('redis')
            ->delay(60);
    }

    public function chargeAfterCommit(int $orderId): void
    {
        // (c) outer ->afterCommit() on dispatch(new Job) (issue #32).
        // Expect overrides {after_commit: true} in dispatched_from[].
        dispatch(new \App\Jobs\ProcessOrder())->afterCommit();
    }
}
