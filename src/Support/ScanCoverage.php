<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;

/**
 * What a scan covered, and what it passed over: the file and directory
 * counts, and PSR-4 directories declared by nested composer.json files that
 * sit outside the scan paths.
 *
 * @internal
 */
final class ScanCoverage
{
    /** Directories never searched for nested composer files. */
    private const IGNORED_ROOTS = ['vendor', 'node_modules', 'storage', 'bootstrap', 'public'];

    /**
     * @return array{files: int, directories: int}
     */
    public static function counts(string $appRoot, ScanScope $scope): array
    {
        $files = 0;
        foreach ($scope->files($appRoot) as $_) {
            $files++;
        }

        return ['files' => $files, 'directories' => count($scope->directories($appRoot))];
    }

    /**
     * PSR-4 directories (app-relative) from nested composer.json files, up to
     * three levels deep, that no scan directory covers.
     *
     * @return array<string, string> directory => the composer.json that declares it
     */
    public static function uncovered(string $appRoot, ScanScope $scope): array
    {
        $files = ComposerPsr4Map::composerFiles($appRoot, ['*/composer.json', '*/*/composer.json', '*/*/*/composer.json']);
        $uncovered = [];

        foreach ($files as $file) {
            $relative = (string) AppPath::relative($appRoot, $file);
            if (Str::startsWith($relative, self::IGNORED_ROOTS)) {
                continue;
            }

            $base = Str::chopEnd($relative, '/composer.json');
            $map = ComposerPsr4Map::fromAppRoot($appRoot, [$relative], includeRoot: false);
            foreach ($map->directories() as $directory) {
                $absolute = AppPath::join($appRoot, $directory);
                if (is_dir($absolute) && ! self::covered($appRoot, $scope, $absolute)) {
                    $uncovered[$directory] = $base.'/composer.json';
                }
            }
        }

        return $uncovered;
    }

    private static function covered(string $appRoot, ScanScope $scope, string $absolute): bool
    {
        foreach ($scope->directories($appRoot) as $directory) {
            if ($absolute === $directory || Str::startsWith($absolute, $directory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return $scope->isExcluded($appRoot, $absolute);
    }
}
