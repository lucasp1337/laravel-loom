<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\OrderReceipt;
use App\Notifications\OrderNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendReceiptJob implements ShouldQueue
{
    public function handle($user): void
    {
        Mail::to('a@b.c')->send(new OrderReceipt);
        $user->notify(new OrderNotice);
    }
}
