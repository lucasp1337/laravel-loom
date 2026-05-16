<?php

declare(strict_types=1);

namespace App\Services;

class Notifier
{
    public function fire(string $eventClass): void
    {
        // Dynamic dispatch — EventScanner must silently ignore this.
        event($eventClass);

        // String interpolation — also ignored.
        $suffix = 'Adjusted';
        event("App\\Events\\Inventory{$suffix}");
    }
}
