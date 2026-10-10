<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\BootObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(BootObserver::class)]
class Tag extends Model
{
}
