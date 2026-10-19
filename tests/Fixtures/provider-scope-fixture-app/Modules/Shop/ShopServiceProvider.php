<?php

namespace Modules\Shop;

use App\Billing\NotifyWarehouse;
use App\Events\OrderShipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderShipped::class, NotifyWarehouse::class);
    }
}
