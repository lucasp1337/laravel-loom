<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

abstract class AbstractQueuedMail extends Mailable implements ShouldQueue
{
    use Queueable;
}
