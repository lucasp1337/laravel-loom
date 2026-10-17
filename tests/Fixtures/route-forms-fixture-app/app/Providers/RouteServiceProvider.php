<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api')->middleware(['api', 'auth'])->group(function () {
            require base_path('routes/api.php');
        });

        Route::middleware('web')->group(fn () => require __DIR__.'/../../routes/nested.php');

        Route::prefix('dyn')->group(function () use ($path) {
            require $path;
        });
    }
}
