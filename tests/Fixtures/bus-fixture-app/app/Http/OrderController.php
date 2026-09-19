<?php

namespace App\Http;

use App\Jobs\Cleanup;
use App\Jobs\ProcessOrder;
use App\Jobs\SendReceipt;
use Illuminate\Support\Facades\Bus;

class OrderController
{
    public function store(array $jobs): void
    {
        Bus::chain([new ProcessOrder, SendReceipt::class])->dispatch();
        Bus::batch([new Cleanup, new SendReceipt])->dispatch();
        Bus::chain($jobs)->dispatch();
    }
}
