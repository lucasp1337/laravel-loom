<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Non-facade Laravel FQCNs Loom matches against — contracts and
 * framework base classes.
 */
enum LaravelClasses: string
{
    case SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';
    case EVENT_SERVICE_PROVIDER = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';
}
