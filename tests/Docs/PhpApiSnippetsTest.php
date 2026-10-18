<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexSchema;
use Lucasp\Loom\Tools\Pages\DocsFixture;
use Lucasp\Loom\Tools\Reference\ApiSnippet;
use Lucasp\Loom\Tools\Reference\ApiSnippetRunner;

/**
 * The PHP API quickstart on `docs/reference/php-api.md` is built from
 * `docs/snippets/php-api/`; each snippet runs here against the docs fixture index.
 */
it('runs every PHP API snippet against the docs fixture index', function (): void {
    $root = dirname(__DIR__, 2);
    // Held in a variable: the fixture deletes its index file when destroyed.
    $fixture = new DocsFixture($root);
    $outputs = ApiSnippetRunner::run($root, $fixture->indexPath());

    expect(array_keys($outputs))->toBe(array_map(static fn (ApiSnippet $snippet): string => $snippet->value, ApiSnippet::cases()))
        ->and($outputs[ApiSnippet::Load->value])->toStartWith(IndexSchema::VERSION."\n")
        ->and($outputs[ApiSnippet::Lookup->value])->toStartWith('app/Listeners/SendReceipt.php:')->toEndWith("NULL\n")
        ->and($outputs[ApiSnippet::Walk->value])->toContain('handled by App\\Listeners\\SendReceipt::handle')
        ->and($outputs[ApiSnippet::Errors->value])->toContain('newer than the supported');
});

it('has a case for every snippet file', function (): void {
    $root = dirname(__DIR__, 2);
    $files = array_map(static fn (string $path): string => basename($path, '.php'), glob($root.'/'.ApiSnippet::DIRECTORY.'/*.php') ?: []);

    expect($files)->toEqualCanonicalizing(array_map(static fn (ApiSnippet $snippet): string => $snippet->value, ApiSnippet::cases()));
});
