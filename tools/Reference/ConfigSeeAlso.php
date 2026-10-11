<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Reference;

/**
 * Hand-written layer for the config tables: a sentence appended to a key's
 * meaning, usually a link to the section that explains it. The meaning itself
 * comes from the comment above the key in config/loom.php.
 */
final class ConfigSeeAlso
{
    /** @return array<string, string> dotted config key => sentence */
    public static function all(): array
    {
        return [
            'scan.paths' => 'See [How directories resolve](#how-directories-resolve).',
            'scan.discover_routes' => 'See [Route files](#route-files).',
            'scan.exclude' => 'See [Exclude globs](#exclude-globs).',
        ];
    }

    public static function for(string $key): string
    {
        return self::all()[$key] ?? '';
    }
}
