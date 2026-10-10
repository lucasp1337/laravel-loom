<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\TraitObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(TraitObserver::class)]
class Comment extends Model
{
}
