<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

abstract class AbstractNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    abstract public function via(object $notifiable): array;
}
