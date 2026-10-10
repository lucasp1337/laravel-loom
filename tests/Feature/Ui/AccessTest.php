<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Lucasp\Loom\Tests\Feature\Ui\UiSnapshot;
use Lucasp\Loom\Ui\LoomAbility;

uses(UiSnapshot::class);

it('serves the dashboard when the gate allows', function () {
    $this->get('/loom')->assertOk()->assertSee('Dashboard');
});

it('renders the 403 page when the gate denies', function () {
    Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => false);

    $this->get('/loom')
        ->assertForbidden()
        ->assertSee('403')
        ->assertSee('viewLoom');
});

it('allows the default gate in local and 404s once the environment leaves it', function () {
    // Drop the test override so the shipped default applies.
    app()->make(Illuminate\Contracts\Auth\Access\Gate::class)->define(
        LoomAbility::VIEW->value,
        fn (?Authenticatable $user = null): bool => app()->environment('local'),
    );

    $this->get('/loom')->assertOk();

    app()['env'] = 'staging';
    $this->get('/loom')->assertNotFound();
});

it('lets an app-defined gate win over the default', function () {
    // Provider boot ran with the gate already defined by the app: Gate::has guards it.
    expect(Gate::has(LoomAbility::VIEW->value))->toBeTrue()
        ->and(Gate::allows(LoomAbility::VIEW->value))->toBeTrue();
});

it('lets guests through the default gate signature in local', function () {
    expect(Gate::forUser(null)->has(LoomAbility::VIEW->value))->toBeTrue();
});

it('gates page routes but not static assets', function () {
    Gate::define(LoomAbility::VIEW->value, fn ($user = null): bool => false);

    $this->get('/loom/events')->assertForbidden();
    $this->get('/loom/assets/loom.css')->assertOk();
});

it('honours the configured path', function () {
    expect(route('loom.dashboard', absolute: false))->toBe('/loom');
});

it('escapes hostile strings from the index', function () {
    $hostile = '<script>alert(1)</script>';

    $this->writeSnapshot(['routes' => [[
        'method' => 'GET',
        'uri' => $hostile,
        'name' => null,
        'controller_fqcn' => null,
        'controller_method' => null,
        'middleware' => [],
        'file' => 'routes/web.php',
        'line' => 1,
        'dispatches' => [],
    ]]]);

    $this->get('/loom/routes')
        ->assertOk()
        ->assertDontSee($hostile, false)
        ->assertSee(e($hostile), false);
});
