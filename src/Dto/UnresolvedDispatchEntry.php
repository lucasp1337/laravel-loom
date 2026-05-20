<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class UnresolvedDispatchEntry
{
    /**
     * @param  'dynamic_class_name'|'container_resolution'|'string_concatenation'|'conditional_dispatch'  $reason
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $expression,
        public readonly string $reason,
    ) {
    }
}
