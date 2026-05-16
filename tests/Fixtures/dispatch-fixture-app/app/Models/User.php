<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\UserObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(UserObserver::class)]
class User
{
}
