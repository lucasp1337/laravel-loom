<?php

declare(strict_types=1);

namespace App\Mail;

use App\Concerns\Queueish;
use Illuminate\Mail\Mailable;

class UsesTraitMail extends Mailable
{
    use Queueish;
}
