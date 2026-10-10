<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;

interface RunsOnQueue extends ShouldQueue
{
}
