<?php

namespace App\Providers;

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->prefix('shop')->name('shop.')->group(function () {
            $this->loadRoutesFrom(base_path('Modules/Shop/routes.php'));
        });

        Route::middleware(['auth', 'verified'])->prefix('account')->group(base_path('Modules/Account/routes.php'));

        Route::controller(ReportController::class)->prefix('reports')->group(base_path('Modules/Reports/routes.php'));

        Route::group(['prefix' => 'legacy', 'as' => 'legacy.', 'middleware' => 'throttle:60,1'], base_path('Modules/Legacy/routes.php'));

        $prefix = config('modules.prefix');
        Route::prefix($prefix)->middleware('web')->group(base_path('Modules/Dynamic/routes.php'));

        Route::prefix('v1')->group(base_path('Modules/Shared/routes.php'));
        Route::prefix('v2')->group(base_path('Modules/Shared/routes.php'));
    }
}
