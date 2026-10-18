<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SplFileInfo;

/** The consumer pages: the README and every page under docs/ except the contributor docs. */
final class HandWrittenPages
{
    private const EXCLUDED = 'docs/contributing/';

    /** @return list<HandWrittenPage> sorted by path */
    public static function all(string $root): array
    {
        $paths = collect((new Filesystem)->allFiles($root.'/docs'))
            ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(static fn (SplFileInfo $file): string => 'docs/'.Str::after($file->getPathname(), $root.'/docs/'))
            ->reject(static fn (string $path): bool => str_starts_with($path, self::EXCLUDED))
            ->push('README.md')
            ->sort()
            ->values();

        return array_values($paths->map(static fn (string $path): HandWrittenPage => new HandWrittenPage(
            $path,
            (string) file_get_contents($root.'/'.$path),
        ))->all());
    }
}
