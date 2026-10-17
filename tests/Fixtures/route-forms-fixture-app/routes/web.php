<?php

use App\Http\Controllers\FiscalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/home', HomeController::class);
Route::prefix('v1')->name('v1.')->post('/sync', SyncController::class);
Route::middleware(['web', 'auth'])->prefix('x')->middleware('auth:admin')->get('/last', HomeController::class);
Route::get('/after', HomeController::class)->prefix('pp')->name('a')->name('b');

Route::middleware('auth')->resource('photos', PhotoController::class, ['only' => ['index']]);
Route::name('x.')->resource('named', PhotoController::class)->only('index');

Route::resource('items', ItemController::class, [
    'only' => ['index', 'show'],
    'names' => 'api.items',
    'parameters' => ['items' => 'item_id'],
    'middleware' => ['auth'],
]);
Route::resource('lists', ItemController::class)
    ->names(['index' => 'lists.all'])
    ->middleware('a')
    ->middlewareFor('show', 'b')
    ->withoutMiddleware('z')
    ->only(['index', 'show', 'update']);

Route::resource('fiscal-positions', FiscalController::class)->only(['show', 'update']);
Route::resource('terms.due-terms', FiscalController::class)->shallow()->only(['index', 'show']);
Route::resource('admin/photos2', PhotoController::class)->only(['index', 'show']);

Route::singleton('profile', ProfileController::class);
Route::singleton('profile2', ProfileController::class)->creatable();
Route::apiSingleton('profile3', ProfileController::class)->creatable();
Route::apiResource('widgets', ItemController::class)->only(['index', 'create']);
Route::apiResources(['tags' => ItemController::class, 'labels' => PhotoController::class], ['except' => ['destroy']]);

Route::view('/welcome', 'welcome')->name('welcome');
Route::redirect('/old', '/new', 301);
Route::permanentRedirect('/older', '/new');
Route::fallback(HomeController::class);

Route::middleware('auth')->group(function () {
    Route::get('/health', HealthController::class)->withoutMiddleware('auth');
    Route::get('/folded', HomeController::class)->middleware(HomeController::class.':admin');
});

Route::softDeletableResource('posts', PhotoController::class);
Route::get($dynamicUri, HomeController::class);
Route::get('/dyn-mw', HomeController::class)->middleware($dynamicMiddleware);

Route::middleware(['auth', 'verified'])->withoutMiddleware('verified')->group(function () {
    Route::get('/group-fluent', HomeController::class);
});
Route::group(['middleware' => ['a', 'b'], 'excluded_middleware' => ['a']], function () {
    Route::get('/group-array', HomeController::class);
});
Route::prefix($dynamicPrefix)->group(function () {
    Route::get('/in-dynamic', HomeController::class);
});
