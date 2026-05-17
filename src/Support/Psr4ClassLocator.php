<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Locate an FQCN's source file under an app root using a Laravel-default
 * PSR-4 mapping convention (App\ => app/).
 *
 * This is a pure path heuristic: returns the absolute path if a file exists
 * at the guessed location, otherwise null. The caller is responsible for
 * parsing the file to confirm it actually declares the expected class.
 */
final class Psr4ClassLocator
{
    /**
     * Return the absolute file path for the FQCN's PSR-4 guess, or null when
     * no file exists at that path.
     */
    public function locate(string $appRoot, string $fqcn): ?string
    {
        $trimmed = ltrim($fqcn, '\\');
        if ($trimmed === '') {
            return null;
        }

        $segments = explode('\\', $trimmed);

        // Map the leading "App" segment (Laravel default root namespace) to
        // the "app/" directory; leave any other root namespace lowercased.
        $segments[0] = $segments[0] === 'App' ? 'app' : strtolower($segments[0]);

        $relative = implode('/', $segments).'.php';
        $absolute = $appRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_file($absolute)) {
            return null;
        }

        return $absolute;
    }
}
