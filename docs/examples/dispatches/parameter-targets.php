<?php

// Classes passed to a method that dispatches its parameter

// app/Services/Messenger.php
namespace App\Services;

use Illuminate\Support\Facades\Mail;

class Messenger implements Sends
{
    public function mail(string $mailClass, string $to): void
    {
        Mail::to($to)->send(new $mailClass); // ✗ dynamic_class_name
    }

    public function fire(object $event): void
    {
        event($event); // ✗ dynamic_class_name
    }

    public function reuse(string $mailClass): void
    {
        $mailClass = $mailClass.'Mail';
        Mail::send(new $mailClass); // ✗ dynamic_class_name
    }
}

// app/Services/Sends.php
namespace App\Services;

interface Sends
{
    public function mail(string $mailClass, string $to): void;
}

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Jobs\ProcessOrder;
use App\Mail\OrderReceipt;
use App\Services\Messenger;
use App\Services\Sends;

class OrderController
{
    public function __construct(private Messenger $messenger, private Sends $sends) {}

    public function store(Messenger $messenger, object $anything): void
    {
        app(Messenger::class)->mail(mailClass: OrderReceipt::class, to: 'a@example.com'); // → mailables[OrderReceipt].sent_from.method OrderController::store
        $this->messenger->mail(OrderReceipt::class, 'a@example.com'); // → mailables[OrderReceipt].sent_from.method OrderController::store
        $messenger->fire(new OrderPlaced); // → events[OrderPlaced].dispatched_from.method OrderController::store
        $this->queue(ProcessOrder::class); // → jobs[ProcessOrder].dispatched_from.method OrderController::store
        $messenger->reuse(OrderReceipt::class); // ✗ not recorded
        $messenger->fire($anything); // ✗ not recorded
        $this->sends->mail(OrderReceipt::class, 'a@example.com'); // ✗ not recorded
    }

    private function queue(string $job): void
    {
        $job::dispatch(); // ✗ dynamic_class_name
    }
}

// app/Listeners/SendReceipt.php
namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\OrderReceipt;
use App\Services\Messenger;

class SendReceipt
{
    public function handle(OrderPlaced $event): void
    {
        (new Messenger)->mail(OrderReceipt::class, 'a@example.com'); // → listeners[SendReceipt].dispatches.confidence low
    }
}
