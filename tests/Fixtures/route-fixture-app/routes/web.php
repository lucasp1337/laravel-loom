<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WidgetController;
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
    Route::get('/', [AdminController::class, 'dashboard']);
});

Route::prefix('api/v1')->name('v1.')->controller(UserController::class)->group(function () {
    Route::get('/status', 'store')->name('status');
});

Route::group(['prefix' => 'dash', 'as' => 'dash.', 'controller' => HomeController::class], function () {
    Route::get('/home', 'index')->name('home');
});

Route::prefix('v2')->group(function () {
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/{id}', [UserController::class, 'show'])->name('show');
    });
});

Route::resource('photos', PhotoController::class);

Route::apiResource('widgets', WidgetController::class);

Route::resource('books', BookController::class)->only(['index', 'show']);

Route::resource('tags', TagController::class)->except(['create', 'edit', 'destroy']);

Route::resource('categories', CategoryController::class)->only(['show']);

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::apiResource('items', ItemController::class);
});

// ---------------------------------------------------------------------------
// Slice 3: middleware
// ---------------------------------------------------------------------------

Route::get('/dashboard', [HomeController::class, 'index'])->middleware('auth');

Route::get('/reports', [HomeController::class, 'index'])->middleware(['auth', 'verified']);

Route::get('/billing', [HomeController::class, 'index'])->middleware('auth')->middleware('throttle:60,1');

Route::middleware(['web', 'auth'])->prefix('account')->group(function () {
    Route::get('/settings', [HomeController::class, 'index'])->middleware('verified');
});

Route::group(['prefix' => 'secure', 'middleware' => ['auth', 'can:admin']], function () {
    Route::get('/audit', [HomeController::class, 'index']);
});

Route::get('/classmw', [HomeController::class, 'index'])->middleware([\App\Http\Middleware\EnsureActive::class]);

Route::middleware('auth')->prefix('dup')->group(function () {
    Route::get('/x', [HomeController::class, 'index'])->middleware('auth');
});

// ---------------------------------------------------------------------------
// Slice 5: controller-method dispatch attribution (cross-link populates dispatches[])
// ---------------------------------------------------------------------------

Route::get('/checkout', [UserController::class, 'checkout'])->name('checkout');
