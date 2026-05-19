<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\SlackChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    public string $connection = 'redis';

    public string $queue = 'notifications';

    public int $tries = 5;

    public int $timeout = 90;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', SlackChannel::class];
    }
}
