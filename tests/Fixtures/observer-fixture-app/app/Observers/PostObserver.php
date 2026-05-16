<?php

declare(strict_types=1);

namespace App\Observers;

class PostObserver
{
    public function saved($model): void {}

    private function creating($model): void {}
}
