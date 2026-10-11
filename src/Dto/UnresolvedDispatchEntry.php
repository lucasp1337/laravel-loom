<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class UnresolvedDispatchEntry
{
    /**
     * @param  'dynamic_class_name'|'container_resolution'|'string_concatenation'|'conditional_dispatch'  $reason
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $expression,
        public string $reason,
    ) {}
}
