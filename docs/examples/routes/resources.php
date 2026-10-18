<?php

// Resource and singleton routes

// routes/web.php
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::resource('photos', PhotoController::class)->only(['index', 'update']); // → routes[/photos].name photos.index → routes[/photos/{photo}].method PUT → routes[/photos/{photo}].method PATCH
Route::apiResource('photos.comments', PhotoController::class)->only('show'); // → routes[/photos/{photo}/comments/{comment}].name photos.comments.show
Route::resource('admin/user-photos', PhotoController::class)->only('show'); // → routes[/admin/user-photos/{user_photo}].name user-photos.show
Route::resource('albums', PhotoController::class)->except(['create', 'edit', 'destroy'])->names(['index' => 'albums.all']); // → routes[/albums].name albums.all
Route::singleton('profile', ProfileController::class); // → routes[/profile].name profile.show → routes[/profile/edit].name profile.edit
Route::apiResources(['posts' => PhotoController::class]); // → routes[/posts].name posts.index
