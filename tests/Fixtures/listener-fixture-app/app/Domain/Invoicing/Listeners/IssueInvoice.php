<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Listeners;

class IssueInvoice
{
    public function handle(\App\Events\OrderPlaced $event): void
    {
        // no-op
    }
}
