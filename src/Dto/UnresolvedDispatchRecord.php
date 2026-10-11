<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\UnresolvedReason;

/**
 * Visitor-emitted unresolved dispatch — `file` is filled by DispatchScanner
 * before promoting to the schema-shape `UnresolvedDispatchEntry`.
 *
 * @internal
 */
final class UnresolvedDispatchRecord
{
    /**
     * @param  value-of<UnresolvedReason>  $reason
     */
    public function __construct(
        public ?string $file,
        public readonly int $line,
        public readonly string $expression,
        public readonly string $reason,
    ) {}
}
