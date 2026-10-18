<?php

// Observer registrations

// app/Models/Order.php
namespace App\Models;

use App\Observers\AuditObserver;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(OrderObserver::class)] // → observers[OrderObserver].registration attribute
class Order extends Model
{
    protected static function booted(): void
    {
        static::observe(AuditObserver::class); // → observers[AuditObserver].registration observe_call
    }
}

// app/Models/Invoice.php
namespace App\Models;

use App\Observers\AuditObserver;
use App\Observers\InvoiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([InvoiceObserver::class])] // → observers[InvoiceObserver].observes App\Models\Invoice
class Invoice extends Model
{
    protected static function booted(): void
    {
        self::observe(AuditObserver::class); // → observers[AuditObserver].observes App\Models\Invoice
    }
}

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(string $observer): void
    {
        User::observe(UserObserver::class); // → observers[UserObserver].observes App\Models\User
        User::observe($observer); // ✗ not recorded
    }
}

// app/Observers/OrderObserver.php
namespace App\Observers;

class OrderObserver
{
    public function created(): void {}
}

// app/Observers/InvoiceObserver.php
namespace App\Observers;

class InvoiceObserver
{
    public function created(): void {}
}

// app/Observers/AuditObserver.php
namespace App\Observers;

class AuditObserver
{
    public function saved(): void {}
}

// app/Observers/UserObserver.php
namespace App\Observers;

class UserObserver
{
    public function deleted(): void {}
}
