<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Listeners\Concerns\HandlesShipped;

class AliasListener
{
    use HandlesShipped {
        handle as handleShipped;
    }
}
