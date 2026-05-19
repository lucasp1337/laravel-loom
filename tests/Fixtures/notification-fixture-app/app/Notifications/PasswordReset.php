<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PasswordReset extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
