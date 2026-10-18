<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/** The classes under src/: all of them by name, and the public `@api` ones. */
final class ApiClasses
{
    private const NAMESPACE = 'Lucasp\\Loom\\';

    /** @return Collection<int, ReflectionClass<object>> the `@api` classes, sorted by name */
    public static function api(string $root): Collection
    {
        return collect(self::names($root))
            ->filter(static fn (string $class): bool => str_contains((string) file_get_contents(self::path($root, $class)), '@api'))
            ->map(static fn (string $class): ReflectionClass => class_exists($class) ? new ReflectionClass($class) : throw new RuntimeException("{$class} does not autoload"))
            ->filter(static fn (ReflectionClass $class): bool => preg_match('/^\s*\*\s*@api\b/m', (string) $class->getDocComment()) === 1)
            ->sortBy(static fn (ReflectionClass $class): string => $class->getName())
            ->values();
    }

    /** @return list<string> every class name under src/, from its file path */
    public static function names(string $root): array
    {
        return array_values(collect((new Filesystem)->allFiles($root.'/src'))
            ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(static fn (SplFileInfo $file): string => self::NAMESPACE.str_replace('/', '\\', Str::of($file->getPathname())->after('/src/')->beforeLast('.php')->value()))
            ->all());
    }

    private static function path(string $root, string $class): string
    {
        return $root.'/src/'.str_replace('\\', '/', Str::after($class, self::NAMESPACE)).'.php';
    }
}
