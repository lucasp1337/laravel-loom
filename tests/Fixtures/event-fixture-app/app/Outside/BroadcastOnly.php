<?php

declare(strict_types=1);

namespace App\Outside;

class BroadcastOnly
{
    public function __construct(public string $channel = '')
    {
    }
}
