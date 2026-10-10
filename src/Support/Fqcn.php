<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;

/**
 * The one home for class-name string handling: leading-backslash
 * normalisation, short and namespace parts, comparison, and `Class@method` /
 * `Class::method` splitting. URL slugs use dots for namespace separators, which
 * class names never contain, so that mapping is lossless and avoids `%5C`.
 *
 * Comparison is case-sensitive everywhere: PHP class names are case-insensitive
 * but the index keys on the spelling found in source, and every consumer
 * already matched exactly.
 *
 * @internal
 */
final class Fqcn
{
    private const string NS = '\\';

    private const string STATIC_SEPARATOR = '::';

    private const string AT_SEPARATOR = '@';

    /** Drops every leading backslash; nothing else (no trimming of whitespace, no case change). */
    public static function normalize(string $fqcn): string
    {
        return ltrim($fqcn, self::NS);
    }

    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, self::NS);

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** The namespace part, or `''` for a class with none. */
    public static function namespaceOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, self::NS);

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    /** Case-sensitive equality ignoring leading backslashes on either side. */
    public static function same(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Splits at the LAST `::`, falling back to the LAST `@`. Neither side is
     * normalised or trimmed. Null when there is no separator.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitMember(string $ref): ?array
    {
        return self::splitLast($ref, self::STATIC_SEPARATOR) ?? self::splitLast($ref, self::AT_SEPARATOR);
    }

    /**
     * Splits at the LAST `::` only. `Class@method` is not split.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitStaticMember(string $ref): ?array
    {
        return self::splitLast($ref, self::STATIC_SEPARATOR);
    }

    /**
     * Splits Laravel's `Class@method` string at the FIRST `@`, so extra `@` stay
     * in the method part. The class is returned verbatim (callers normalize).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitAtMember(string $ref): ?array
    {
        if (! Str::contains($ref, self::AT_SEPARATOR)) {
            return null;
        }

        return [Str::before($ref, self::AT_SEPARATOR), Str::after($ref, self::AT_SEPARATOR)];
    }

    public static function toSlug(string $fqcn): string
    {
        return Str::replace(self::NS, '.', $fqcn);
    }

    public static function fromSlug(string $slug): string
    {
        return Str::replace('.', self::NS, $slug);
    }

    /** @return array{0: string, 1: string}|null */
    private static function splitLast(string $ref, string $separator): ?array
    {
        $pos = strrpos($ref, $separator);

        return $pos === false ? null : [substr($ref, 0, $pos), substr($ref, $pos + strlen($separator))];
    }
}
