<?php

declare(strict_types=1);

namespace Lucasp\Loom\Contracts;

interface Scanner
{
    /**
     * Return partial index data keyed by schema section name. Entries are
     * either section-output DTOs from `src/Dto/` or raw schema-shaped
     * associative arrays. `IndexBuilder` serializes DTOs before cross-link.
     *
     * Internal sections (underscore-prefixed, e.g. `_dispatch_sites`) bypass
     * serialization and stay in the producer's shape.
     *
     * @return array<string, array<int, object|array<string, mixed>>>
     */
    public function scan(string $appRoot): array;
}
