<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;
use Lucasp\Loom\Support\OptionalPackage;
use Lucasp\Loom\Ui\LoomAbility;

it('serves no UI routes without livewire', function () {
    $this->bootWithout([OptionalPackage::LIVEWIRE]);
    Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => true);

    expect(Route::has('loom.dashboard'))->toBeFalse()
        ->and(Route::has('loom.assets'))->toBeFalse();
    $this->get('/loom')->assertNotFound();
});

it('still scans without livewire and hints at the UI', function () {
    $this->bootWithout([OptionalPackage::LIVEWIRE]);
    config()->set('loom.index_path', sys_get_temp_dir().'/loom-opt-'.bin2hex(random_bytes(6)).'.json');

    $this->artisan('loom:scan')
        ->expectsOutputToContain('composer require --dev livewire/livewire')
        ->assertExitCode(0);
});

it('does not hint at the UI when it is switched off', function () {
    $this->bootWithout([OptionalPackage::LIVEWIRE], ['loom.ui.enabled' => false]);
    config()->set('loom.index_path', sys_get_temp_dir().'/loom-opt-'.bin2hex(random_bytes(6)).'.json');

    $this->artisan('loom:scan')->doesntExpectOutputToContain('livewire/livewire')->assertExitCode(0);
});

it('fails loom:mcp with an install hint without laravel/mcp', function () {
    $this->bootWithout([OptionalPackage::MCP]);

    $this->artisan('loom:mcp')
        ->expectsOutputToContain('composer require --dev laravel/mcp')
        ->assertExitCode(1);
    expect(app(Registrar::class)->getLocalServer('loom'))->toBeNull();
});

it('does not register the MCP server when switched off', function () {
    $this->bootWithout([], ['loom.mcp.enabled' => false]);

    expect(app(Registrar::class)->getLocalServer('loom'))->toBeNull();
    $this->artisan('loom:mcp')->expectsOutputToContain('loom.mcp.enabled')->assertExitCode(1);
});

it('registers the MCP server by default', function () {
    expect(app(Registrar::class)->getLocalServer('loom'))->not->toBeNull();
});

it('registers the UI routes when livewire is installed and enabled', function () {
    expect(Route::has('loom.dashboard'))->toBeTrue();
});
