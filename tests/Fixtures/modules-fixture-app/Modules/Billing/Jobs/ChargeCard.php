<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ChargeCard implements ShouldQueue
{
    public function handle(): void
    {
    }
}
