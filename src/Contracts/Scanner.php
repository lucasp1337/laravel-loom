<?php

declare(strict_types=1);

namespace Lucasp\Loom\Contracts;

/**
 * Internal seam between `IndexBuilder` and the built-in scanners. Not an
 * extension point: sections are a closed set and nothing registers
 * third-party scanners.
 *
 * A scanner is stateless and keeps three concerns apart: discovery (which
 * files and classes to read), parsing (visitors through `AstWalker`) and
 * emission (schema-shaped entries, sorted by FQCN or the natural key, no JSON
 * encoding). A section it does not contribute to is omitted; contributions to
 * the same section from several scanners are concatenated.
 *
 * @internal
 */
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
