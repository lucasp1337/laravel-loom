<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lucasp\Loom\Tools\Reference\ApiClasses;
use Lucasp\Loom\Tools\Reference\ApiSnippet;
use Lucasp\Loom\Tools\Reference\SnippetRunner;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\SourceContext;
use ReflectionClass;

/**
 * The PHP API page: the quickstart snippets with the output they print
 * against the docs fixture index, and every `@api` class with the first
 * sentence of its docblock.
 */
final class PhpApiPages implements RegionSource
{
    public function __construct(private readonly SourceContext $context) {}

    public function regions(): array
    {
        return [
            new GeneratedRegion(Region::PhpApiQuickstart, $this->quickstart()),
            new GeneratedRegion(Region::PhpApiClasses, $this->classes()),
        ];
    }

    private function quickstart(): string
    {
        $root = $this->context->root;
        $outputs = SnippetRunner::run($root, $this->context->fixture->indexPath(), ApiSnippet::cases());

        return collect(ApiSnippet::cases())->map(static function (ApiSnippet $snippet) use ($root, $outputs): string {
            $heading = $snippet->title() === null ? '' : "## {$snippet->title()}\n\n";

            return $heading.SnippetRunner::markdown($root, $snippet, $outputs[$snippet->path()]);
        })->implode("\n\n");
    }

    /** One table per namespace of the `@api` classes under src/. */
    private function classes(): string
    {
        return ApiClasses::api($this->context->root)
            ->groupBy(static fn (ReflectionClass $class): string => $class->getNamespaceName())
            ->sortKeys()
            ->map(static fn (Collection $classes, string $namespace): string => "### `{$namespace}`\n\n".Markdown::table(
                ['Class', 'Summary'],
                array_values($classes->map(static fn (ReflectionClass $class): array => [
                    "`{$class->getShortName()}`",
                    Markdown::cell(self::summary((string) $class->getDocComment())),
                ])->all()),
            ))
            ->implode("\n\n");
    }

    /** First sentence of a docblock's first paragraph, `{@see X}` as code. */
    private static function summary(string $docblock): string
    {
        $paragraph = Str::of($docblock)
            ->replaceMatches('#^/\*\*|\*/$#', '')
            ->replaceMatches('/^\s*\* ?/m', '')
            ->trim()
            ->before("\n\n")
            ->replaceMatches('/\{@see\s+([^}]+)\}/', '`$1`')
            ->squish();

        return (string) $paragraph->split('/(?<=\.)(?<!e\.g\.)\s+(?=[A-Z`])/')->first();
    }
}
