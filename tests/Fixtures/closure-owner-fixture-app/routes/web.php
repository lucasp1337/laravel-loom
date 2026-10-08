<?php

declare(strict_types=1);

use App\Events\ArrowHit;
use App\Events\GroupHit;
use App\Events\RouteHit;
use Illuminate\Support\Facades\Route;

Route::get('/closure', function () {
    RouteHit::dispatch();
});

Route::post('/arrow', fn () => event(new ArrowHit))->name('arrow');

Route::prefix('admin')->group(function () {
    Route::get('/nested', function () {
        event(new GroupHit);
    });
});
