<?php

declare(strict_types=1);

namespace App\Observers;

class UserObserver
{
    public function creating($model): void {}

    public function updated($model): void {}

    public function deleted($model): void {}

    public function utility(): void {}
}
