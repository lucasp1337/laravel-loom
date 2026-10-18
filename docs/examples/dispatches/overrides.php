<?php

// Chain modifiers at the dispatch

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Jobs\ProcessOrder;
use App\Mail\OrderReceipt;
use Illuminate\Support\Facades\Mail;

class OrderController
{
    public function store(int $seconds): void
    {
        ProcessOrder::dispatch()->onQueue('high')->onConnection('redis'); // → jobs[ProcessOrder].dispatched_from.overrides {"queue":"high","connection":"redis"}
        dispatch(new ProcessOrder)->delay(60)->afterCommit(); // → jobs[ProcessOrder].dispatched_from.overrides {"delay":60,"after_commit":true}
        Mail::to('a@example.com')->locale('es')->send(new OrderReceipt); // → mailables[OrderReceipt].sent_from.overrides.locale es
        Mail::mailer('postmark')->to('a@example.com')->send(new OrderReceipt); // → mailables[OrderReceipt].sent_from.overrides null
        Mail::to('a@example.com')->send((new OrderReceipt)->mailer('postmark')); // → mailables[OrderReceipt].sent_from.overrides.mailer postmark

        ProcessOrder::dispatch()->delay(now()->addMinutes(5)); // → jobs[ProcessOrder].dispatched_from.overrides null
        ProcessOrder::dispatch()->delay($seconds); // → jobs[ProcessOrder].dispatched_from.overrides null

        $job = new ProcessOrder;
        $job->onQueue('high');
        dispatch($job); // → jobs[ProcessOrder].dispatched_from.overrides null
    }
}
