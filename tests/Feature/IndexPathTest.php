<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Query\SnapshotIndexSource;
use Lucasp\Loom\Ui\UiContext;

it('defaults to the storage snapshot for MCP and UI', function () {
    $default = app()->storagePath('loom/index.json');

    expect(app(IndexRepository::class)->path())->toBe($default)
        ->and(app(UiContext::class)->source->path())->toBe($default);
});

it('shares a configured path between MCP and UI, with the UI override winning', function () {
    config()->set('loom.index_path', '/tmp/shared.json');

    expect(app(IndexRepository::class)->path())->toBe('/tmp/shared.json')
        ->and(app(UiContext::class)->source->path())->toBe('/tmp/shared.json');

    app()->forgetInstance(UiContext::class);
    config()->set('loom.ui.index_path', '/tmp/ui.json');
    expect(app(UiContext::class)->source->path())->toBe('/tmp/ui.json');
});

it('never gives the UI the auto-scanning MCP source', function () {
    $source = app(UiContext::class)->source;

    expect($source)->toBeInstanceOf(SnapshotIndexSource::class)
        ->and($source)->not->toBeInstanceOf(IndexRepository::class);
});
