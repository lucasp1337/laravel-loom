<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\SendReceipt;
use App\Models\User;
use App\Observers\UserObserver;

class EventServiceProvider
{
    protected $listen = [
        OrderPlaced::class => [SendReceipt::class],
    ];

    public function boot(): void
    {
        User::observe(UserObserver::class);
    }
}
