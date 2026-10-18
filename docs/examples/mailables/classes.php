<?php

// Mailable classes

// app/Mail/OrderShipped.php
namespace App\Mail;

use Illuminate\Mail\Mailable;

class OrderShipped extends Mailable {} // → mailables[OrderShipped]

// app/Mail/WeeklyDigest.php
namespace App\Mail;

use Illuminate\Mail\Mailables\Content;

class WeeklyDigest // → mailables[WeeklyDigest]
{
    public function content(): Content
    {
        return new Content(view: 'mail.digest');
    }
}

// app/Mail/Support/Formatter.php
namespace App\Mail\Support;

class Formatter {} // ✗ not recorded

// app/Billing/InvoiceMail.php
namespace App\Billing;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

class InvoiceMail extends Mailable implements ShouldQueue {} // → mailables[InvoiceMail].queued true

// app/Http/Controllers/InvoiceController.php
namespace App\Http\Controllers;

use App\Billing\InvoiceMail;
use Illuminate\Support\Facades\Mail;

class InvoiceController
{
    public function store(): void
    {
        Mail::to('a@example.com')->send(new InvoiceMail); // → mailables[InvoiceMail].sent_from.method InvoiceController::store
    }
}
