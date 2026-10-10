<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Notification;

class InvoiceOverdue extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
