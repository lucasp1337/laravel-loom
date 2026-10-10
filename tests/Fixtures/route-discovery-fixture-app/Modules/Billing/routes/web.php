<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/billing', [PageController::class, 'index']);
Route::prefix('v2')->group(__DIR__.DIRECTORY_SEPARATOR.'nested.php');
