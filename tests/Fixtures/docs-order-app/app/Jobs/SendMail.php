<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SendMail implements ShouldQueue
{
    public function __construct(public int $orderId, public object $followUp) {}

    public function handle(): void
    {
        event($this->followUp);
    }
}
