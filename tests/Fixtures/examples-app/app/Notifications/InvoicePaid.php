<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
