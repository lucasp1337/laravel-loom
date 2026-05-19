<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

class OrderShipped extends Mailable implements ShouldQueue
{
    use Queueable;

    public string $connection = 'redis';

    public string $queue = 'mail';

    public int $tries = 3;

    public int $timeout = 60;
}
