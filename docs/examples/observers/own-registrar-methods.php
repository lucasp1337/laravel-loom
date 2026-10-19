<?php

// A model's own static hook method

// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public static function creating($callback): void {}

    protected static function booted(): void
    {
        static::creating(fn (Order $order) => null); // ✗ not recorded
        static::created(fn (Order $order) => null); // → model_events[eloquent.created: App\Models\Order].handled_by.method {closure}
    }
}

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Models\Order;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Order::creating(fn (Order $order) => null); // ✗ not recorded
    }
}
