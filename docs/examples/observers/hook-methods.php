<?php

// Observer hook methods

// app/Models/Order.php
namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(OrderObserver::class)]
class Order extends Model {}

// app/Observers/BaseObserver.php
namespace App\Observers;

abstract class BaseObserver
{
    public function deleted(): void {} // → observers[OrderObserver].hooks deleted
}

// app/Observers/TracksRestores.php
namespace App\Observers;

trait TracksRestores
{
    public function restored(): void {} // → observers[OrderObserver].hooks restored
}

// app/Observers/OrderObserver.php
namespace App\Observers;

class OrderObserver extends BaseObserver // → observers.hooks ["created","deleted","restored","trashed"]
{
    use TracksRestores;

    protected function created(): void {} // → observers[OrderObserver].hooks created

    public function trashed(): void {} // → observers[OrderObserver].hooks trashed

    public function booted(): void {}
}
