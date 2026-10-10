<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;

/**
 * The one home for turning an app root plus a relative path into an absolute
 * path and back. Absolute paths use the platform separator; relative paths
 * (what the index carries) are always forward-slashed.
 *
 * @internal
 */
final class AppPath
{
    /** The app root without trailing separators. */
    public static function root(string $appRoot): string
    {
        return Str::rtrim($appRoot, '/\\');
    }

    /** `<root>/`, with the platform separator. */
    public static function prefix(string $appRoot): string
    {
        return self::root($appRoot).DIRECTORY_SEPARATOR;
    }

    /**
     * The absolute path of a forward-slashed `$relative` under the app root.
     */
    public static function join(string $appRoot, string $relative): string
    {
        return self::prefix($appRoot).Str::replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Forward-slashed path relative to the app root, or null when `$absolute`
     * is not under it.
     */
    public static function relative(string $appRoot, string $absolute): ?string
    {
        $prefix = self::prefix($appRoot);
        if (! Str::startsWith($absolute, $prefix)) {
            return null;
        }

        return Str::replace(DIRECTORY_SEPARATOR, '/', Str::chopStart($absolute, $prefix));
    }

    /**
     * The path as shown to a person: app-relative when inside the root, as
     * given otherwise, always forward-slashed.
     */
    public static function display(string $appRoot, string $path): string
    {
        return self::relative($appRoot, $path) ?? Str::replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    /** True for a POSIX absolute path, a UNC path or a Windows drive path. */
    public static function isAbsolute(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || Str::isMatch('#^[A-Za-z]:[\\/]#', $path);
    }
}
