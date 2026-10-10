<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('shop')->group(base_path('Modules/Shop/routes/shop.php'));

        Route::group(['prefix' => 'legacy'], [
            __DIR__.'/../../Modules/Shop/routes/legacy.php',
            __DIR__.'/../../Modules/Shop/routes/skipped.php',
        ]);

        $name = 'dynamic';
        Route::group(['prefix' => 'dyn'], base_path('routes/'.$name.'.php'));
        Route::middleware('web')->group('routes/relative.php');
        Route::middleware('web')->group(__DIR__.'/../../../outside.php');
        Route::group(['prefix' => 'inline'], function () {
            Route::get('/inline', fn () => 'ok');
        });
    }
}
