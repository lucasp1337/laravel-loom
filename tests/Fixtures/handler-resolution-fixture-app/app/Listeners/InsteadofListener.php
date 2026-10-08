<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Listeners\Concerns\HandlesRefunded;
use App\Listeners\Concerns\HandlesShipped;

class InsteadofListener
{
    use HandlesShipped, HandlesRefunded {
        HandlesShipped::handle insteadof HandlesRefunded;
        HandlesRefunded::handle as handleRefunded;
    }
}
