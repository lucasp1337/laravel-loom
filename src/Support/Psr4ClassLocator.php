<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Map an FQCN to its likely on-disk path using Laravel's default PSR-4
 * convention (`App\` → `app/`). Pure path heuristic — caller is
 * responsible for parsing the file.
 */
final class Psr4ClassLocator
{
    public function locate(string $appRoot, string $fqcn): ?string
    {
        $trimmed = ltrim($fqcn, '\\');
        if ($trimmed === '') {
            return null;
        }

        $segments = explode('\\', $trimmed);
        $segments[0] = $segments[0] === 'App' ? 'app' : strtolower($segments[0]);

        $relative = implode('/', $segments).'.php';
        $absolute = $appRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return is_file($absolute) ? $absolute : null;
    }
}
