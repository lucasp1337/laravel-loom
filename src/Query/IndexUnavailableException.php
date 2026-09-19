<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use RuntimeException;

/** @internal */
final class IndexUnavailableException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Loom index not found at [{$path}]. Run `php artisan loom:scan` first.");
    }

    public static function unloadable(string $path, ?string $reason = null): self
    {
        return new self("Loom index could not be loaded from [{$path}]".($reason !== null ? ": {$reason}" : '.'));
    }
}
