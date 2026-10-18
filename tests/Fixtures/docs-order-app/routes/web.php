<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store'])->middleware('auth')->name('orders.store');

Route::get('/health', fn () => 'ok');
