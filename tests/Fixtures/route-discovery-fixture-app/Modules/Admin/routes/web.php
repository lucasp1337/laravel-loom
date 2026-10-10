<?php

use App\Events\ModuleHit;
use Illuminate\Support\Facades\Route;

Route::get('/admin', function () {
    event(new ModuleHit);

    return 'admin';
});
