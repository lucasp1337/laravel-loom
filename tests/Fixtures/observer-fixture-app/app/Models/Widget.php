<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DualObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(DualObserver::class)]
class Widget
{
}
