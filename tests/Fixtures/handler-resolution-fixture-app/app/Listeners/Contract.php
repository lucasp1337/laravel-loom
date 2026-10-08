<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

interface Contract
{
    public function handle(OrderPlaced $event): void;
}
