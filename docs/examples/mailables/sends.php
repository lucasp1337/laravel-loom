<?php

// Sending mail

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Mail\OrderReceipt;
use Illuminate\Support\Facades\Mail;

class OrderController
{
    public function store(object $mailable, array $data): void
    {
        Mail::send(new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store
        Mail::sendNow(new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store
        Mail::queue(new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store
        Mail::later(60, new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store
        Mail::to('a@example.com')->cc('b@example.com')->bcc('c@example.com')->send(new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store
        Mail::to('a@example.com')->queue(new OrderReceipt); // → mailables[OrderReceipt].sent_from.method OrderController::store

        Mail::send($mailable); // ✗ dynamic_class_name
        Mail::send('emails.welcome', $data, fn ($message) => $message->to('a@example.com')); // ✗ view_mail
        Mail::raw('Shipped', fn ($message) => $message->to('a@example.com')); // ✗ not recorded
        Mail::html('<p>Shipped</p>', fn ($message) => $message->to('a@example.com')); // ✗ not recorded
    }
}
