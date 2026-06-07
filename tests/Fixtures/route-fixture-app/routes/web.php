<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::post('/users', [UserController::class, 'store']);

Route::get('/invoke', DashboardController::class);

Route::get('/invoke-array', [DashboardController::class]);

Route::put('/legacy', 'App\\Http\\Controllers\\UserController@update');

Route::get('/closure', fn () => null);

Route::match(['get', 'post'], '/multi', [UserController::class, 'multi']);

Route::any('/any', [UserController::class, 'any']);

Route::prefix('admin')->group(function () {
    Route::get('/panel', [AdminController::class, 'panel']);
});

Route::resource('photos', PhotoController::class);
