<?php

declare(strict_types=1);

namespace App\Notifications;

class IndirectlyQueuedNotification extends AbstractQueuedNotification
{
    public string $queue = 'notify-indirect';

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
