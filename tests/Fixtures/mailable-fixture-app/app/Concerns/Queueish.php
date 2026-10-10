<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Bus\Queueable;

/**
 * A trait cannot declare an interface, so using it never makes a class queued.
 */
trait Queueish
{
    use Queueable;
}
