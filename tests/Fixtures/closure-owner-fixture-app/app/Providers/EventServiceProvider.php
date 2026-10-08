<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Child;
use App\Events\Owned;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
{
    public function boot(): void
    {
        Event::listen(Owned::class, function ($e) {
            event(new Child);
        });
    }
}
