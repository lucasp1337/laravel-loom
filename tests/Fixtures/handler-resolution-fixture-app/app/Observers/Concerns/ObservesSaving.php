<?php

declare(strict_types=1);

namespace App\Observers\Concerns;

trait ObservesSaving
{
    public function saving($model): void
    {
    }
}
