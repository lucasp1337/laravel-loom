<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Dedicated fixture notification. Its via() declares broad channels so the
 * dispatch-time channel filter is a genuine override.
 */
class ChannelOverrideAlert extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }
}
