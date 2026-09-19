<?php

declare(strict_types=1);

use Lucasp\Loom\Tests\Feature\Ui\UiSnapshot;
use Lucasp\Loom\Ui\Asset;

uses(UiSnapshot::class);

it('serves each whitelisted asset with its content type', function (Asset $asset) {
    $response = $this->get('/loom/assets/'.$asset->value);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe($asset->contentType())
        ->and((string) $response->headers->get('Cache-Control'))->toContain('immutable');
})->with(fn () => Asset::cases());

it('404s anything outside the whitelist', function (string $path) {
    $this->get('/loom/assets/'.$path)->assertNotFound();
})->with([
    'composer manifest' => 'composer.json',
    'source php' => 'loom.php',
    'unknown css' => 'other.css',
    'encoded traversal' => '..%2F..%2Fcomposer.json',
    'encoded traversal 2' => '%2e%2e%2f%2e%2e%2fcomposer.json',
    'license file' => 'cytoscape.LICENSE',
    'dot dot' => '..',
]);

it('does not match traversal through a nested path', function () {
    $this->get('/loom/assets/../../composer.json')->assertNotFound();
    $this->get('/loom/assets/loom.css/../../../composer.json')->assertNotFound();
});

it('keeps cytoscape off the shell assets', function () {
    $this->get('/loom')->assertDontSee(Asset::CYTOSCAPE->value, false)->assertSee(Asset::CSS->value, false);
});

it('pins the vendored cytoscape version', function () {
    expect((string) file_get_contents(Asset::CYTOSCAPE->path()))->toContain('3.30.2');
});
