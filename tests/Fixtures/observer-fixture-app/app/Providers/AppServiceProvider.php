<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Comment;
use App\Models\Widget;
use App\Observers\CommentObserver;
use App\Observers\DualObserver;
use Illuminate\Support\Facades\Event;

class AppServiceProvider
{
    public function boot(): void
    {
        Comment::observe(CommentObserver::class);

        // Precedence test: also registered via attribute on Widget.
        Widget::observe(DualObserver::class);

        // Path C: only model_events entry; InvoiceHandler is NOT an observer entry.
        Event::listen('eloquent.deleted: App\\Models\\Product', 'App\\Handlers\\InvoiceHandler@deleted');

        // Dedupe test: (User, creating) also reachable through UserObserver's attribute path.
        Event::listen('eloquent.creating: App\\Models\\User', 'App\\Observers\\UserObserver@creating');

        // Closure handler — must be ignored.
        Event::listen('eloquent.saved: App\\Models\\Foo', fn () => null);

        // Dynamic event name — must be ignored.
        $dynamic = 'eloquent.creating: App\\Models\\Bar';
        Event::listen($dynamic, \App\Observers\DualObserver::class);
    }
}
