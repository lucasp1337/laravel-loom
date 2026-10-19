<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexSchema;
use Lucasp\Loom\Tools\Pages\DocsFixture;
use Lucasp\Loom\Tools\Reference\ApiSnippet;
use Lucasp\Loom\Tools\Reference\RecipeSnippet;
use Lucasp\Loom\Tools\Reference\Snippet;
use Lucasp\Loom\Tools\Reference\SnippetRunner;

/**
 * The PHP API quickstart on `docs/reference/php-api.md` and the recipes under
 * `docs/recipes/` are built from `docs/snippets/`; each snippet runs here
 * against the docs fixture index.
 */
it('runs every PHP API snippet against the docs fixture index', function (): void {
    $root = dirname(__DIR__, 2);
    // Held in a variable: the fixture deletes its index file when destroyed.
    $fixture = new DocsFixture($root);
    $outputs = SnippetRunner::run($root, $fixture->indexPath(), ApiSnippet::cases());

    expect(array_keys($outputs))->toBe(array_map(static fn (ApiSnippet $snippet): string => $snippet->path(), ApiSnippet::cases()))
        ->and($outputs[ApiSnippet::Load->path()])->toStartWith(IndexSchema::VERSION."\n")
        ->and($outputs[ApiSnippet::Lookup->path()])->toStartWith('app/Listeners/SendReceipt.php:')->toEndWith("NULL\n")
        ->and($outputs[ApiSnippet::Walk->path()])->toContain('handled by App\\Listeners\\SendReceipt::handle')
        ->and($outputs[ApiSnippet::Errors->path()])->toContain('newer than the supported');
});

it('runs every recipe snippet on its own against the docs fixture index', function (RecipeSnippet $snippet, string $expected): void {
    $root = dirname(__DIR__, 2);
    $fixture = new DocsFixture($root);

    expect(SnippetRunner::run($root, $fixture->indexPath(), [$snippet])[$snippet->path()])->toContain($expected);
})->with([
    'event chain' => [RecipeSnippet::EventChain, "      → event App\\Events\\ReceiptLogged\n        App\\Listeners\\WriteAuditLog::handle"],
    'event chain to a mailable' => [RecipeSnippet::EventChain, "  → mailable App\\Mail\\OrderReceipt\n"],
    'routes without auth' => [RecipeSnippet::RoutesWithoutAuth, "GET /health  routes/web.php:8\n"],
    'job triggers' => [RecipeSnippet::JobTriggers, "      route POST /orders\n"],
]);

it('has a case for every snippet file', function (string $directory, array $cases): void {
    $root = dirname(__DIR__, 2);
    $files = array_map(static fn (string $path): string => basename($path, '.php'), glob($root.'/'.$directory.'/*.php') ?: []);

    expect($files)->toEqualCanonicalizing(array_map(static fn (Snippet&BackedEnum $snippet): string|int => $snippet->value, $cases));
})->with([
    'php api' => [ApiSnippet::DIRECTORY, ApiSnippet::cases()],
    'recipes' => [RecipeSnippet::DIRECTORY, RecipeSnippet::cases()],
]);
