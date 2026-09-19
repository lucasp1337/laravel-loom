<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The index schema version ("MAJOR.MINOR"), independent of the package version.
 * MAJOR bumps only on breaking shape changes; MINOR on additive ones. Readers
 * accept the same MAJOR with any MINOR.
 */
final class IndexSchema
{
    public const VERSION = '1.0';

    public static function major(string $version): ?int
    {
        return preg_match('/^(\d+)\.\d+$/', $version, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Assert a decoded document is readable by this package.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws IndexLoadException
     */
    public static function assertSupported(array $data): void
    {
        $found = $data[MetaField::SCHEMA_VERSION->value] ?? null;
        $foundMajor = is_string($found) ? self::major($found) : null;
        if ($foundMajor === null) {
            throw IndexLoadException::missingSchemaVersion(self::VERSION);
        }

        $supported = (int) self::major(self::VERSION);
        if ($foundMajor < $supported) {
            throw IndexLoadException::olderSchema((string) $found, self::VERSION);
        }
        if ($foundMajor > $supported) {
            throw IndexLoadException::newerSchema((string) $found, self::VERSION);
        }
    }

    /**
     * Assert two documents share a schema MAJOR (used by the differ).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     *
     * @throws IndexLoadException
     */
    public static function assertComparable(array $old, array $new): void
    {
        $versions = [];
        foreach ([$old, $new] as $doc) {
            $v = $doc[MetaField::SCHEMA_VERSION->value] ?? null;
            if (! is_string($v) || self::major($v) === null) {
                throw IndexLoadException::missingSchemaVersion(self::VERSION);
            }
            $versions[] = $v;
        }

        if (self::major($versions[0]) !== self::major($versions[1])) {
            throw IndexLoadException::incomparableSchemas($versions[0], $versions[1]);
        }
    }
}
