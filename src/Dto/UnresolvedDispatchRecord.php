<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * Visitor-emitted unresolved dispatch — `file` is filled by DispatchScanner
 * before promoting to the schema-shape `UnresolvedDispatchEntry`.
 */
final class UnresolvedDispatchRecord
{
    /**
     * @param  'dynamic_class_name'|'container_resolution'|'string_concatenation'|'conditional_dispatch'  $reason
     */
    public function __construct(
        public ?string $file,
        public readonly int $line,
        public readonly string $expression,
        public readonly string $reason,
    ) {
    }
}
