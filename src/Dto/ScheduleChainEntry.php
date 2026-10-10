<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ScheduleKind;
use Lucasp\Loom\Support\Ast\Args;

/**
 * A raw scheduler chain captured by ScheduleChainVisitor (pre-translation).
 *
 * @internal
 */
final class ScheduleChainEntry
{
    /**
     * @param  list<ChainLink>  $chain
     */
    public function __construct(
        public readonly ScheduleKind $kind,
        public readonly string $rootMethod,
        public readonly Args $rootArgs,
        public readonly array $chain,
        public readonly int $line,
    ) {}
}
