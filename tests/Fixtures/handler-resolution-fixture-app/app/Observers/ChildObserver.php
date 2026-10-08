<?php

declare(strict_types=1);

namespace App\Observers;

class ChildObserver extends BaseObserver
{
    public function deleted($model): void
    {
    }

    public function notAHook($model): void
    {
    }
}
