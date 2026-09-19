<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

/**
 * FQCN helpers. URL slugs use dots for namespace separators, which class names
 * never contain, so the mapping is lossless and avoids `%5C` in links.
 *
 * @internal
 */
final class Fqcn
{
    public static function toSlug(string $fqcn): string
    {
        return str_replace('\\', '.', $fqcn);
    }

    public static function fromSlug(string $slug): string
    {
        return str_replace('.', '\\', $slug);
    }

    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    public static function namespace(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }
}
