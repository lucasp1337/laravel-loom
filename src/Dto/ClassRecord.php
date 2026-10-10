<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A discovered class with only FQCN + line — used by EventClassVisitor, ObserverClassVisitor.
 *
 * @internal
 */
final readonly class ClassRecord
{
    public function __construct(
        public string $fqcn,
        public int $line,
    ) {}
}
