<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lucasp\Loom\Tools\Reference\ApiSnippet;
use Lucasp\Loom\Tools\Reference\ApiSnippetRunner;
use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\Regions\Region;
use Lucasp\Loom\Tools\SourceContext;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * The PHP API page: the quickstart snippets with the output they print
 * against the docs fixture index, and every `@api` class with the first
 * sentence of its docblock.
 */
final class PhpApiPages implements RegionSource
{
    private const NAMESPACE = 'Lucasp\\Loom\\';

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
        $outputs = ApiSnippetRunner::run($this->context->root, $this->context->fixture->indexPath());

        return collect(ApiSnippet::cases())->map(function (ApiSnippet $snippet) use ($outputs): string {
            $code = Str::of((string) file_get_contents($this->context->root.'/'.$snippet->path()))->after('<?php')->trim();

            return "## {$snippet->title()}\n\n```php\n{$code}\n```\n\n```text title=\"Output\"\n".trim($outputs[$snippet->value])."\n```";
        })->implode("\n\n");
    }

    /** One table per namespace of the `@api` classes under src/. */
    private function classes(): string
    {
        return $this->apiClasses()
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

    /** @return Collection<int, ReflectionClass<object>> sorted by name */
    private function apiClasses(): Collection
    {
        return collect((new Filesystem)->allFiles($this->context->root.'/src'))
            ->filter(static fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), '@api'))
            ->map(static fn (SplFileInfo $file): string => self::NAMESPACE.str_replace('/', '\\', Str::of($file->getPathname())->after('/src/')->beforeLast('.php')->value()))
            ->map(static fn (string $class): ReflectionClass => class_exists($class) ? new ReflectionClass($class) : throw new RuntimeException("{$class} does not autoload"))
            ->filter(static fn (ReflectionClass $class): bool => preg_match('/^\s*\*\s*@api\b/m', (string) $class->getDocComment()) === 1)
            ->sortBy(static fn (ReflectionClass $class): string => $class->getName())
            ->values();
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
