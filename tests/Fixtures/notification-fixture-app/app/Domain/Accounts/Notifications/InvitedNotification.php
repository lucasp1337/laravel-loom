<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Notifications;

use Illuminate\Notifications\Notification;

class InvitedNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
