<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A discovered class with only FQCN + line — used by EventClassVisitor, ObserverClassVisitor. */
final class ClassRecord
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
    ) {
    }
}
