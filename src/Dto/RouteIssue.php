<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\UnresolvedRouteReason;

/**
 * Something a route visitor or expander could not read, before the file is known.
 *
 * @internal
 */
final readonly class RouteIssue
{
    public function __construct(
        public UnresolvedRouteReason $reason,
        public int $line,
        public string $expression,
    ) {}
}
