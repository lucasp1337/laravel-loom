<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class FanOut
{
    public function __construct(
        public string $event,
        public int $handlerCount,
        public int $dispatchSiteCount,
        public int $downstreamReach,
    ) {
    }
}
