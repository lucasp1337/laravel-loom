<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem helpers for scanners: recursively yield PHP files and
 * normalise absolute paths to forward-slashed paths relative to the
 * scanned app root.
 */
trait ScannerFilesystem
{
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

    /**
     * @throws \InvalidArgumentException when the path is outside the app root
     *                                   (the index never carries absolute paths)
     */
    private function relativePath(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($absolute, $prefix)) {
            throw new \InvalidArgumentException("Path is outside the app root: {$absolute}");
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($prefix)));
    }
}
