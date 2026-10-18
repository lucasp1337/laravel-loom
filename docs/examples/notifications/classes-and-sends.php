<?php

// Notification classes and sends

// app/Notifications/OrderShipped.php
namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrderShipped extends Notification {} // → notifications[OrderShipped]

// app/Billing/PaymentDue.php
namespace App\Billing;

use Illuminate\Notifications\Notification;

class PaymentDue extends Notification // → notifications[PaymentDue].channels ["mail"]
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}

// app/Http/Controllers/InvoiceController.php
namespace App\Http\Controllers;

use App\Billing\PaymentDue;
use App\Models\User;
use App\Notifications\InvoicePaid;
use Illuminate\Support\Facades\Notification;

class InvoiceController
{
    public function store(User $user, ?User $owner, $users): void
    {
        $user->notify(new PaymentDue); // → notifications[PaymentDue].notified_from.method InvoiceController::store
        $user->notifyNow(new InvoicePaid); // → notifications[InvoicePaid].notified_from.method InvoiceController::store
        $owner?->notify(new InvoicePaid); // → notifications[InvoicePaid].notified_from.method InvoiceController::store
        Notification::send($users, new InvoicePaid); // → notifications[InvoicePaid].notified_from.method InvoiceController::store
        Notification::sendNow($users, new InvoicePaid, ['database']); // → notifications[InvoicePaid].notified_from.channels ["database"]
        Notification::route('mail', 'ops@example.com')->notify(new InvoicePaid); // → notifications[InvoicePaid].notified_from.method InvoiceController::store
    }
}
