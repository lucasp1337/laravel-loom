<?php

declare(strict_types=1);

namespace App\Observers;

use App\Mail\OrderReceipt;
use App\Notifications\OrderNotice;
use Illuminate\Support\Facades\Mail;

class UserObserver
{
    public function created($user): void
    {
        Mail::to('a@b.c')->send(new OrderReceipt);
        $user->notify(new OrderNotice);
    }
}
