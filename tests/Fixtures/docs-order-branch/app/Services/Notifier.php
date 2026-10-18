<?php

namespace App\Services;

class Notifier
{
    public function send(string $eventClass): void
    {
        event($eventClass);
    }
}
