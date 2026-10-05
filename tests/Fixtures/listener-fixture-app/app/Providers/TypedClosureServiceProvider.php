<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\RestockScheduled;
use App\Events\StockLow;
use Illuminate\Support\Facades\Event;

class TypedClosureServiceProvider
{
    public function boot(): void
    {
        Event::listen(function (OrderPlaced $e) {
            return null;
        });
        Event::listen(fn (StockLow|RestockScheduled $e) => null);
        Event::listen(function ($e) {
            return null;
        });
        Event::listen(fn (object $e) => null);
    }
}
