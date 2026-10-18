<?php

// Route files loaded by the app

// bootstrap/app.php
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )
    ->create();

// routes/web.php
use Illuminate\Support\Facades\Route;

Route::get('/orders', fn () => null); // → routes.middleware ["web"]

Route::middleware('auth')->group(function () {
    require __DIR__.'/account.php';
});

// routes/account.php
use Illuminate\Support\Facades\Route;

Route::get('/account', fn () => null); // → routes.middleware ["web","auth"]

// routes/api.php
use Illuminate\Support\Facades\Route;

Route::get('/orders', fn () => null); // → routes.uri /api/orders → routes.middleware ["api"]

// app/Providers/BillingServiceProvider.php
namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('modules/billing/routes.php'));
        Route::prefix('admin')->group(app_path('Admin/routes.php'));
    }
}

// modules/billing/routes.php
use Illuminate\Support\Facades\Route;

Route::get('/invoices', fn () => null); // → routes.uri /invoices

// app/Admin/routes.php
use Illuminate\Support\Facades\Route;

Route::get('/reports', fn () => null); // → routes.uri /admin/reports
