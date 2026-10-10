<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final class ListenerClassRecord
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly bool $queued,
    ) {}
}
