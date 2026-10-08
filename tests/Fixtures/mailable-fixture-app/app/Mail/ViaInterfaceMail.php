<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contracts\RunsOnQueue;
use Illuminate\Mail\Mailable;

class ViaInterfaceMail extends Mailable implements RunsOnQueue
{
}
