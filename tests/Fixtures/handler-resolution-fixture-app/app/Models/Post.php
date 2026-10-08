<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ChildObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(ChildObserver::class)]
class Post extends Model
{
}
