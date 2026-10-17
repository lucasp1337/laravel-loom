<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Dto\UnresolvedDispatchEntry;

/**
 * What {@see ClassPrimitiveDiscovery} found: the primitive's entries, and the
 * dispatch targets it could not locate.
 *
 * @template TEntry of object
 *
 * @internal
 */
final readonly class DiscoveryResult
{
    /**
     * @param  list<TEntry>  $entries
     * @param  list<UnresolvedDispatchEntry>  $unresolved
     */
    public function __construct(
        public array $entries,
        public array $unresolved,
    ) {}
}
