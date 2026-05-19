<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

abstract class AbstractMailable extends Mailable
{
    abstract public function build(): self;
}
