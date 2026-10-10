<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/home', [HomeController::class, 'index'])->name('home')->middleware('verified');

Route::prefix('inner')->name('inner.')->middleware('can:manage')->group(function () {
    Route::get('/panel', fn () => 'panel')->name('panel');
});

Route::prefix('deep')->group(base_path('Modules/Deep/routes.php'));
