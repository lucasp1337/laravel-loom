<?php

declare(strict_types=1);

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

it('denies outside the local environment by default and allows in local', function () {
    // Drop the test override so the shipped default applies.
    app()->make(Illuminate\Contracts\Auth\Access\Gate::class)->define(
        LoomAbility::VIEW->value,
        fn (?Illuminate\Contracts\Auth\Authenticatable $user = null): bool => app()->environment('local'),
    );

    $this->get('/loom')->assertForbidden();

    app()['env'] = 'local';
    $this->get('/loom')->assertOk();
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
