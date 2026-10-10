<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

class NullableListener
{
    public function handle(?OrderPlaced $event): void
    {
    }
}
