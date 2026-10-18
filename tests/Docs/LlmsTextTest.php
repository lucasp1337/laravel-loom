<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\ReferencePages;
use Lucasp\Loom\Tools\Site\LlmsText;
use Lucasp\Loom\Tools\Site\NavPage;
use Lucasp\Loom\Tools\Site\SiteConfig;

/**
 * `docs/llms.txt` and `docs/llms-full.txt` are generated from the site nav
 * and the rendered pages, and served at the site root.
 */
it('keeps llms.txt and llms-full.txt in sync with the pages', function (): void {
    $root = dirname(__DIR__, 2);
    $outputs = (new ReferencePages($root))->outputs();

    foreach (['docs/'.LlmsText::INDEX, 'docs/'.LlmsText::FULL] as $file) {
        expect($outputs)->toHaveKey($file)
            ->and((string) file_get_contents($root.'/'.$file))->toBe($outputs[$file], "{$file} drifted; run `just docs-generate`");
    }
});

it('links every nav page from llms.txt in the llmstxt.org shape', function (): void {
    $root = dirname(__DIR__, 2);
    $site = SiteConfig::read($root);
    $index = (string) file_get_contents($root.'/docs/'.LlmsText::INDEX);

    expect($index)->toStartWith("# {$site->name}\n\n> {$site->description}\n\n## ")
        ->toContain("\n## Optional\n");
    foreach ($site->pages as $page) {
        expect($index)->toContain("]({$page->url($site->url)})");
    }
});

it('concatenates the consumer pages into llms-full.txt without comments', function (): void {
    $root = dirname(__DIR__, 2);
    $full = (string) file_get_contents($root.'/docs/'.LlmsText::FULL);

    expect($full)->not->toContain('<!--')
        ->toContain("\n# Getting started\n")
        ->toContain("\n# Schema\n")
        ->not->toContain("\n# Architecture\n");
});

it('maps a page path to its address on the built site', function (string $path, string $url): void {
    expect((new NavPage('S', $path))->url('https://x.test/docs/'))->toBe($url);
})->with([
    'home' => ['index.md', 'https://x.test/docs/'],
    'top-level page' => ['getting-started.md', 'https://x.test/docs/getting-started/'],
    'nested page' => ['reference/schema.md', 'https://x.test/docs/reference/schema/'],
    'section index' => ['reference/detection/index.md', 'https://x.test/docs/reference/detection/'],
    'readme' => ['contributing/adr/README.md', 'https://x.test/docs/contributing/adr/'],
]);
