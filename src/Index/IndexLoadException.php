<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use RuntimeException;

/**
 * Thrown by {@see IndexLoader} when a Loom index cannot be read, decoded, or
 * structurally recognised as an index payload.
 */
final class IndexLoadException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self("Unable to read Loom index file: {$path}");
    }

    public static function invalidJson(string $message): self
    {
        return new self("Loom index is not valid JSON: {$message}");
    }

    public static function notAnObject(): self
    {
        return new self('Loom index must decode to a JSON object.');
    }

    public static function missingMeta(string $key): self
    {
        return new self("Loom index is missing the required `{$key}` envelope field.");
    }

    public static function missingSchemaVersion(string $supported): self
    {
        return new self("Loom index has no `schema_version` (written by a pre-1.0 Loom). This Loom reads schema {$supported}; re-run `php artisan loom:scan` to regenerate it.");
    }

    public static function olderSchema(string $found, string $supported): self
    {
        return new self("Loom index uses schema {$found}, older than the supported {$supported}. Re-run `php artisan loom:scan` to regenerate it.");
    }

    public static function newerSchema(string $found, string $supported): self
    {
        return new self("Loom index uses schema {$found}, newer than the supported {$supported}. Upgrade laravel-loom, or re-run `php artisan loom:scan` with the installed version.");
    }

    public static function incomparableSchemas(string $old, string $new): self
    {
        return new self("Cannot diff indexes with different schema majors ({$old} vs {$new}). Re-run `php artisan loom:scan` on both sides with the same Loom version.");
    }
}
