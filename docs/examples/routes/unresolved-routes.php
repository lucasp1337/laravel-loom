<?php

// Routes Loom cannot read

// routes/web.php
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

$uri = '/photos';
$verbs = ['get', 'post'];
$name = 'photos';
$only = ['index'];
$middleware = ['auth'];
$prefix = 'v2';

Route::inertia('/about', 'About'); // ✗ unknown_route_call
Route::get($uri, [PhotoController::class, 'index']); // ✗ dynamic_uri
Route::match($verbs, '/search', [PhotoController::class, 'search']); // ✗ dynamic_verbs
Route::resource($name, PhotoController::class); // ✗ dynamic_resource
Route::resource('albums', PhotoController::class)->only($only); // ✗ dynamic_resource_options → routes[/albums/{album}/edit].name albums.edit
Route::get('/tags', [PhotoController::class, 'tags'])->middleware($middleware); // ✗ dynamic_middleware

Route::prefix($prefix)->group(function () { // ✗ dynamic_group_attribute
    Route::get('/feed', [PhotoController::class, 'feed']);
});
