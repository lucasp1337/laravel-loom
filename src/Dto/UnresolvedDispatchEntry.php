<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\UnresolvedReason;

/** @internal */
final readonly class UnresolvedDispatchEntry
{
    /**
     * @param  value-of<UnresolvedReason>  $reason
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $expression,
        public string $reason,
    ) {}
}
