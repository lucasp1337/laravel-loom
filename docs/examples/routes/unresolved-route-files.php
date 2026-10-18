<?php

// Route files Loom cannot follow

// app/Providers/RouteServiceProvider.php
namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(string $module): void
    {
        $this->loadRoutesFrom(base_path("modules/{$module}/routes.php")); // ✗ route_file_dynamic
        $this->loadRoutesFrom('routes/admin.php'); // ✗ route_file_relative
        $this->loadRoutesFrom(__DIR__.'/../../../shared/routes.php'); // ✗ route_file_outside_root
        Route::group([], base_path('modules/missing/routes.php')); // ✗ route_file_not_found
    }
}
