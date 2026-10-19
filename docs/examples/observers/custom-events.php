<?php

// Custom observable events

// app/Models/Order.php
namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    protected $observables = ['archived', 'shipped'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addObservableEvents('refunded'); // ✗ not recorded
    }
}

// app/Observers/OrderObserver.php
namespace App\Observers;

use App\Events\OrderShipped;

class OrderObserver // → observers.hooks ["archived","created"] → model_events[eloquent.archived: App\Models\Order].handled_by.method archived
{
    public function archived(): void
    {
        event(new OrderShipped); // → observers[OrderObserver].dispatches.target OrderShipped
    }

    public function created(): void {}

    public function refunded(): void {} // ✗ not recorded
}
