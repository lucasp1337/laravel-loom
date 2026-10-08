<?php

declare(strict_types=1);

namespace App\Observers;

abstract class BaseObserver
{
    public function created($model): void
    {
    }

    public function updated($model): void
    {
    }
}
