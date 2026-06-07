<?php

declare(strict_types=1);

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/api/users', [UserController::class, 'index'])->name('api.users.index');

Route::get('/api/users/{user}', [UserController::class, 'show'])->name('api.users.show');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/ping', [UserController::class, 'index'])->name('ping');
});
