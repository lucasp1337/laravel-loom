<?php

declare(strict_types=1);

namespace App\Observers;

use App\Observers\Concerns\ObservesSaving;

class TraitObserver
{
    use ObservesSaving;

    public function created($model): void
    {
    }
}
