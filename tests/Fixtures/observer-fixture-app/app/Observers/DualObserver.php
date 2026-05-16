<?php

declare(strict_types=1);

namespace App\Observers;

class DualObserver
{
    public function created($model): void {}

    public function updated($model): void {}
}
