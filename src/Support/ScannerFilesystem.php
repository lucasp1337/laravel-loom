<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use FilesystemIterator;
use Lucasp\Loom\Support\Ast\AstWalker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem helpers for scanners: recursively yield PHP files and
 * normalise absolute paths to forward-slashed paths relative to the
 * scanned app root.
 *
 * @internal
 */
trait ScannerFilesystem
{
    private ?ScanScope $scope = null;

    private ?RouteFileDiscovery $routeDiscovery = null;

    private ?ProviderFiles $providerFiles = null;

    protected function scope(): ScanScope
    {
        return $this->scope ??= ScanScope::default();
    }

    /**
     * PHP files in the scan directories (or their `$subdirectory`), with
     * excluded files removed.
     *
     * @return iterable<SplFileInfo>
     */
    protected function scanFiles(string $appRoot, ?PrimitiveDirectory $subdirectory = null): iterable
    {
        return $this->scope()->files($appRoot, $subdirectory?->value);
    }

    /**
     * The scan files plus the registered providers no scan directory holds:
     * a provider registers listeners, observers and schedules wherever it lives.
     *
     * @return iterable<SplFileInfo>
     */
    protected function registrationFiles(string $appRoot, AstWalker $walker): iterable
    {
        foreach ($this->scanFiles($appRoot) as $file) {
            yield $file;
        }

        $this->providerFiles ??= new ProviderFiles($this->scope(), $walker, new Psr4ClassLocator($this->scope()->composerFiles()));
        foreach ($this->providerFiles->outsideScanPaths($appRoot) as $path) {
            yield new SplFileInfo($path);
        }
    }

    /**
     * PHP files in the route directories (`scan.route_paths`) and the route
     * files discovered from loading calls, minus excluded files. Independent of
     * the scan paths: route closures can dispatch, so the dispatch scan reads
     * them too.
     *
     * @return iterable<SplFileInfo>
     */
    protected function routeFiles(string $appRoot): iterable
    {
        return $this->routeDiscovery?->files($appRoot) ?? $this->scope()->routeFiles($appRoot);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function iteratePhpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if (! $entry->isFile()) {
                continue;
            }
            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            yield $entry;
        }
    }

    /** True when the app-relative file lies under `<scan directory>/<directory>/`. */
    protected function isUnderPrimitiveDirectory(string $appRoot, string $relativeFile, PrimitiveDirectory $directory): bool
    {
        return $this->scope()->isUnder(
            $appRoot,
            AppPath::join($appRoot, $relativeFile),
            $directory->value,
        );
    }

    /**
     * @throws \InvalidArgumentException when the path is outside the app root
     *                                   (the index never carries absolute paths)
     */
    private function relativePath(string $appRoot, string $absolute): string
    {
        return AppPath::relative($appRoot, $absolute)
            ?? throw new \InvalidArgumentException("Path is outside the app root: {$absolute}");
    }
}
