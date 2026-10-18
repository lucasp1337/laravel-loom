<?php

// Verbs and actions

// routes/web.php
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ShowDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index'); // → routes.controller_method index → routes.name orders.index
Route::post('/orders', 'App\Http\Controllers\OrderController@store'); // → routes.method POST → routes.controller_method store
Route::put('/orders/{order}', [OrderController::class, 'update']); // → routes.method PUT
Route::patch('/orders/{order}', [OrderController::class, 'update']); // → routes.method PATCH
Route::delete('/orders/{order}', [OrderController::class, 'destroy']); // → routes.method DELETE
Route::options('/orders', [OrderController::class, 'options']); // → routes.method OPTIONS
Route::any('/ping', [OrderController::class, 'ping']); // → routes.method ANY
Route::match(['get', 'post'], '/search', [OrderController::class, 'search']); // → routes.method GET → routes.method POST
Route::get('/dashboard', ShowDashboard::class); // → routes.controller_fqcn App\Http\Controllers\ShowDashboard → routes.controller_method __invoke
Route::get('/health', fn () => 'ok'); // → routes.controller_fqcn null

// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

class OrderController {}

// app/Http/Controllers/ShowDashboard.php
namespace App\Http\Controllers;

class ShowDashboard
{
    public function __invoke(): void {}
}
