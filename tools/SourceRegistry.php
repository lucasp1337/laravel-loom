<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools;

use Illuminate\Support\Collection;
use Lucasp\Loom\Tools\Pages\RegionSource;
use ReflectionClass;

/** Finds region sources: every concrete RegionSource class in tools/Pages. */
final class SourceRegistry
{
    private const DIRECTORY = __DIR__.'/Pages';

    private const NAMESPACE = 'Lucasp\\Loom\\Tools\\Pages\\';

    /** @return list<class-string<RegionSource>> */
    public static function classes(): array
    {
        $classes = Collection::make(glob(self::DIRECTORY.'/*.php') ?: [])
            ->map(static fn (string $file): string => self::NAMESPACE.basename($file, '.php'))
            ->filter(static fn (string $class): bool => class_exists($class)
                && is_subclass_of($class, RegionSource::class)
                && (new ReflectionClass($class))->isInstantiable())
            ->sort()
            ->values();

        /** @var list<class-string<RegionSource>> */
        return $classes->all();
    }

    /** @return list<RegionSource> */
    public static function discover(SourceContext $context): array
    {
        return array_map(
            static fn (string $class): RegionSource => new $class($context),
            self::classes(),
        );
    }
}
