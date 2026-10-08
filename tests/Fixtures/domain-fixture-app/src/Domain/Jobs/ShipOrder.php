<?php

declare(strict_types=1);

namespace Acme\Shop\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class ShipOrder implements ShouldQueue
{
    public function handle(): void
    {
    }
}
