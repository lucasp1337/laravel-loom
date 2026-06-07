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
}
