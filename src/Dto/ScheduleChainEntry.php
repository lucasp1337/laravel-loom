<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ScheduleKind;
use PhpParser\Node;

/** A raw scheduler chain captured by ScheduleChainVisitor (pre-translation). */
final class ScheduleChainEntry
{
    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $rootArgs
     * @param  list<ScheduleChainLink>  $chain
     */
    public function __construct(
        public readonly ScheduleKind $kind,
        public readonly string $rootMethod,
        public readonly array $rootArgs,
        public readonly array $chain,
        public readonly int $line,
    ) {
    }
}
