<?php

declare(strict_types=1);

use Lucasp\Loom\Tests\Feature\Ui\UiSnapshot;

uses(UiSnapshot::class);

it('shows the run-a-scan state when the snapshot is missing', function () {
    unlink($this->snapshot);

    $this->get('/loom')
        ->assertStatus(503)
        ->assertSee('No index found')
        ->assertSee('php artisan loom:scan');

    $this->get('/loom/events')->assertSee('No index found');
});

it('shows the could-not-load state for an invalid snapshot', function () {
    file_put_contents($this->snapshot, '{"not":"an index"}');

    $this->get('/loom')
        ->assertStatus(503)
        ->assertSee('Index could not be loaded')
        ->assertSee('php artisan loom:scan');
});

it('picks up a snapshot written after the first miss', function () {
    unlink($this->snapshot);
    $this->get('/loom')->assertSee('No index found');

    $this->writeSnapshot();
    $this->get('/loom')->assertSee('Dashboard');
});
