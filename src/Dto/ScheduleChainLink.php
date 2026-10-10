<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use PhpParser\Node;

/**
 * One link in a `Schedule::command(...)->daily()` chain.
 *
 * @internal
 */
final class ScheduleChainLink
{
    /**
     * @param  array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder>  $args
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
    ) {}
}
