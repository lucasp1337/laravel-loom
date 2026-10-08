<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\RunsOnQueue;
use Illuminate\Notifications\Notification;

class ViaInterfaceNotification extends Notification implements RunsOnQueue
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
