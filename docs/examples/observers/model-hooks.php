<?php

// Model hook registrations

// app/Models/Order.php
namespace App\Models;

use App\Events\OrderPlaced;
use App\Support\OrderHooks;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected static function booted(): void
    {
        static::created(function (Order $order) { // → model_events[eloquent.created: App\Models\Order].handled_by.method {closure} → closure_listeners.registration model_hook
            event(new OrderPlaced); // → closure_listeners[eloquent.created: App\Models\Order].dispatches.target OrderPlaced
        });
        static::saved([OrderHooks::class, 'saved']); // → model_events[eloquent.saved: App\Models\Order].handled_by.handler OrderHooks
        static::deleted(OrderHooks::class); // → model_events[eloquent.deleted: App\Models\Order].handled_by.method handle
        static::updated('App\Support\OrderHooks@updated'); // → model_events[eloquent.updated: App\Models\Order].handled_by.method updated
    }
}

// app/Models/Concerns/HasUuid.php
namespace App\Models\Concerns;

trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(fn ($model) => null); // → model_events[eloquent.creating: App\Models\User].handled_by.method {closure} → model_events[eloquent.creating: App\Models\Invoice].handled_by.method {closure}
    }
}

// app/Models/User.php
namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasUuid;
}

// app/Models/Invoice.php
namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuid;

    protected static function boot(): void
    {
        parent::boot();

        $callback = fn () => null;
        static::deleting($callback); // ✗ unreadable_model_hook
    }
}

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Models\Order;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(\Closure $callback): void
    {
        Order::restored(fn (Order $order) => null); // → model_events[eloquent.restored: App\Models\Order].handled_by.method {closure}
        Order::retrieved($callback); // ✗ not recorded
    }
}

// app/Support/OrderHooks.php
namespace App\Support;

class OrderHooks
{
    public function saved(): void {}

    public function handle(): void {}

    public function updated(): void {}
}
