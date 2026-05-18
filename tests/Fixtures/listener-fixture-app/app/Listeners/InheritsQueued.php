<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLow;

/**
 * Inherits ShouldQueue from AbstractQueuedListener. Used to verify
 * ListenerScanner detects indirect ShouldQueue via the resolver.
 */
class InheritsQueued extends AbstractQueuedListener
{
    public function handle(StockLow $event): void
    {
    }
}
