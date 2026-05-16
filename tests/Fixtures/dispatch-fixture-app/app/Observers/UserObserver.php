<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\OrderConfirmationSent;
use App\Jobs\SendReceipt;
use Illuminate\Support\Facades\Bus;

class UserObserver
{
    public function created($user): void
    {
        Bus::dispatch(new SendReceipt());
    }

    public function utility(): void
    {
        event(new OrderConfirmationSent());
    }
}
