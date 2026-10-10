<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Concerns\Queueish;
use Illuminate\Notifications\Notification;

class UsesTraitNotification extends Notification
{
    use Queueish;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
