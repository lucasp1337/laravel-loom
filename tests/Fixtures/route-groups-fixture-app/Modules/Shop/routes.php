<?php

use App\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'shop')->name('index');
Route::get('/cart', [CartController::class, 'show'])->name('cart')->middleware('auth');

Route::name('admin.')->prefix('admin')->group(function () {
    Route::post('/items', fn () => 'item')->name('items');
});
