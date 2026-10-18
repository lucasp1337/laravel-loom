<?php

// View, redirect and fallback routes

// routes/web.php
use Illuminate\Support\Facades\Route;

Route::view('/about', 'pages.about'); // → routes.kind view → routes.method GET
Route::redirect('/home', '/dashboard'); // → routes.kind redirect → routes.method ANY
Route::permanentRedirect('/start', '/dashboard'); // → routes.kind redirect
Route::fallback(fn () => abort(404)); // → routes.kind fallback → routes.uri /{fallbackPlaceholder}
