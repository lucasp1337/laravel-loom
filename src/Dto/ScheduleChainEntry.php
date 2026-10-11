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
final readonly class ScheduleChainEntry
{
    /**
     * @param  list<ChainLink>  $chain
     */
    public function __construct(
        public ScheduleKind $kind,
        public string $rootMethod,
        public Args $rootArgs,
        public array $chain,
        public int $line,
    ) {}
}
