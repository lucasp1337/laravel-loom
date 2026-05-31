<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Second dedicated #33 fixture notification, used for the no-filter and
 * notify-form control sites.
 */
class ChannelOverrideReminder extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }
}
