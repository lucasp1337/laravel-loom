<?php

declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class AbstractJob implements ShouldQueue
{
}
