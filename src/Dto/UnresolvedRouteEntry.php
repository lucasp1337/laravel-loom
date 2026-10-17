<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\UnresolvedRouteReason;

/**
 * A route form Loom saw but could not read in full.
 *
 * @internal
 */
final readonly class UnresolvedRouteEntry
{
    public function __construct(
        public string $file,
        public int $line,
        public string $expression,
        public UnresolvedRouteReason $reason,
    ) {}
}
