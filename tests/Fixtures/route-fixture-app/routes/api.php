<?php

declare(strict_types=1);

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/api/users', [UserController::class, 'index'])->name('api.users.index');

Route::get('/api/users/{user}', [UserController::class, 'show'])->name('api.users.show');
