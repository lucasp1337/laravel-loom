<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Listeners\Concerns\HandlesShipped;

class HiddenListener
{
    use HandlesShipped {
        handle as protected;
    }
}
