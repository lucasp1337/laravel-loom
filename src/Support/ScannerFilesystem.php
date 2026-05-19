<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shared filesystem helpers for scanners. Every scanner needs to walk a
 * directory of `.php` files and normalise absolute paths to forward-slashed
 * paths relative to the scanned app root. Centralising both keeps the
 * scanner classes focused on discovery and emission.
 *
 * Usage: `use ScannerFilesystem;` in the scanner; call
 * `$this->iteratePhpFiles($dir)` and `$this->relativePath($appRoot, $absolute)`.
 */
trait ScannerFilesystem
{
    /**
     * Yield every `.php` file under `$dir`, recursively. Skips dot entries
     * and any file whose extension is not `php` (case-insensitive).
     *
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
     * Strip the app-root prefix from an absolute path and normalise to
     * forward slashes. Returns the absolute path unchanged if it doesn't
     * sit under `$appRoot` (caller's mistake; we don't try to relativise
     * paths from elsewhere).
     */
    private function relativePath(string $appRoot, string $absolute): string
    {
        $prefix = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
    }
}
