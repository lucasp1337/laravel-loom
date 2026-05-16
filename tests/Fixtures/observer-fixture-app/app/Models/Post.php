<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PostObserver;

class Post
{
    public static function booted(): void
    {
        static::observe(PostObserver::class);
    }
}
