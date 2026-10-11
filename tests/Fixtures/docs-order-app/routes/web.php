<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

Route::get('/health', fn () => 'ok');
