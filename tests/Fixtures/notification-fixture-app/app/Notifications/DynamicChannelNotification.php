<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DynamicChannelNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (property_exists($notifiable, 'prefers_mail') && $notifiable->prefers_mail) {
            return ['mail'];
        }

        return ['database'];
    }
}
