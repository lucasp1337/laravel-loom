<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

class AuditSubscriber
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe($events): array
    {
        return [
            OrderPlaced::class => 'audit',
        ];
    }

    public function audit(OrderPlaced $event): void {}
}
