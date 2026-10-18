<?php

// Channels from via()

// app/Notifications/OrderShipped.php
namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrderShipped extends Notification // → notifications.channels ["mail","database"] → notifications.channels_dynamic false
{
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }
}

// app/Notifications/StockLow.php
namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StockLow extends Notification // → notifications.channels ["mail","slack"] → notifications.channels_dynamic true
{
    public function via(object $notifiable): array
    {
        $channels = ['mail'];
        if ($notifiable->wantsSlack) {
            $channels[] = 'slack';
        }

        return $channels;
    }
}

// app/Notifications/PriceChanged.php
namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PriceChanged extends Notification // → notifications.channels [] → notifications.channels_dynamic true
{
    public function via(object $notifiable): array
    {
        return $notifiable->preferredChannels();
    }
}

// app/Notifications/BaseNotification.php
namespace App\Notifications;

use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}

// app/Notifications/RefundIssued.php
namespace App\Notifications;

class RefundIssued extends BaseNotification {} // → notifications.channels [] → notifications.channels_dynamic false
