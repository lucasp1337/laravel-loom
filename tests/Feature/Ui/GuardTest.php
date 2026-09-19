<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Lucasp\Loom\Tests\Feature\Ui\UiSnapshot;
use Lucasp\Loom\Ui\LoomAbility;
use Lucasp\Loom\Ui\LoomUiServiceProvider;

uses(UiSnapshot::class);

/** @param  array<string, mixed>  $config */
function bootLoomAs(object $test, string $environment, array $config = [], bool $openGate = true): void
{
    $test->bootUiAs($environment, $config, $openGate);
}

function loomRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'loom.'))
        ->values()
        ->all();
}

it('mounts nothing in production', function () {
    bootLoomAs($this, 'production');

    $this->get('/loom')->assertNotFound();
    $this->get('/loom/assets/loom.css')->assertNotFound();
    $this->get('/loom/events')->assertNotFound();
    expect(loomRouteNames())->toBe([])
        ->and(Route::has('loom.dashboard'))->toBeFalse()
        ->and(Route::has('loom.assets'))->toBeFalse()
        ->and(View::exists('loom::layouts.app'))->toBeFalse();
});

it('serves in local', function () {
    $this->get('/loom')->assertOk();
    $this->get('/loom/assets/loom.css')->assertOk();
    expect(Route::has('loom.dashboard'))->toBeTrue();
});

it('serves on a listed staging environment', function () {
    bootLoomAs($this, 'staging', ['loom.ui.environments' => ['local', 'staging']]);

    $this->get('/loom')->assertOk();
    $this->get('/loom/assets/loom.css')->assertOk();
});

it('does not mount in an unlisted environment', function () {
    bootLoomAs($this, 'staging');

    $this->get('/loom')->assertNotFound();
});

it('skips production without allow_in_production and logs a warning', function () {
    $log = sys_get_temp_dir().'/loom-guard-'.bin2hex(random_bytes(4)).'.log';

    bootLoomAs($this, 'production', [
        'loom.ui.environments' => ['production'],
        'logging.default' => 'single',
        'logging.channels.single.path' => $log,
    ]);

    $this->get('/loom')->assertNotFound();
    $this->get('/loom/assets/loom.css')->assertNotFound();
    expect((string) @file_get_contents($log))->toContain('allow_in_production');
    @unlink($log);
});

it('mounts in production only with allow_in_production', function () {
    bootLoomAs($this, 'production', [
        'loom.ui.environments' => ['production'],
        'loom.ui.allow_in_production' => true,
    ]);

    $this->get('/loom')->assertOk();
});

it('is off everywhere when disabled', function () {
    bootLoomAs($this, 'local', ['loom.ui.enabled' => false], openGate: false);

    $this->get('/loom')->assertNotFound();
    $this->get('/loom/assets/loom.css')->assertNotFound();
    expect(Route::has('loom.dashboard'))->toBeFalse()
        ->and(Gate::has(LoomAbility::VIEW->value))->toBeFalse()
        ->and(View::exists('loom::layouts.app'))->toBeFalse();
});

it('404s at request time when routes registered in local are served in production', function () {
    expect(Route::has('loom.dashboard'))->toBeTrue();

    app()['env'] = 'production';

    $this->get('/loom')->assertNotFound();
    $this->get('/loom/events')->assertNotFound();
    $this->get('/loom/assets/loom.css')->assertNotFound();
});

it('still respects an app gate override in local', function () {
    Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => false);

    $this->get('/loom')->assertForbidden();
});

it('defines the default gate only where the UI mounts', function () {
    bootLoomAs($this, 'local', openGate: false);
    expect(Gate::has(LoomAbility::VIEW->value))->toBeTrue();

    bootLoomAs($this, 'production', openGate: false);
    expect(Gate::has(LoomAbility::VIEW->value))->toBeFalse();
});

it('still publishes the config in production', function () {
    bootLoomAs($this, 'production');

    $paths = ServiceProvider::pathsToPublish(LoomUiServiceProvider::class, 'loom-config');

    expect($paths)->not->toBeEmpty();
});
