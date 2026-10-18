<?php

// Groups, chains and middleware

// routes/web.php
use App\Http\Controllers\OrderController;
use App\Http\Middleware\Audit;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('v1')->name('v1.')->get('/orders', [OrderController::class, 'index'])->name('orders'); // → routes.uri /v1/orders → routes.name v1.orders → routes.middleware ["auth"]

Route::prefix('admin')->middleware('web')->middleware(['auth', 'verified'])->controller(OrderController::class)->group(function () {
    Route::get('/orders', 'index'); // → routes.uri /admin/orders → routes.middleware ["auth","verified"] → routes.controller_method index

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/exports', 'export')->middleware(Audit::class.':strict')->withoutMiddleware('verified'); // → routes.middleware ["auth","throttle:10,1","App\\Http\\Middleware\\Audit:strict"]
    });
});
