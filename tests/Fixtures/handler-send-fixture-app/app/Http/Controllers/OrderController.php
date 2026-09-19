<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\OrderReceipt;
use App\Notifications\OrderNotice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class OrderController
{
    public function store($request): void
    {
        Mail::to('a@b.c')->send(new OrderReceipt);
        Notification::send($request->user(), new OrderNotice);
        $request->user()->notify(new OrderNotice);
    }
}
