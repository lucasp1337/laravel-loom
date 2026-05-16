<?php

declare(strict_types=1);

namespace App\Outside;

class CustomEvent
{
    public function __construct(public string $reason = '')
    {
    }
}
