<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

class InvokableListener
{
    public function __invoke(OrderPlaced $event): void
    {
    }
}
