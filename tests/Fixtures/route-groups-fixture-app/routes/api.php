<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => 'pong')->name('api.ping');
