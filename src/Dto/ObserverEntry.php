<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class ObserverEntry
{
    /**
     * @param  list<string>  $hooks
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly int $line,
        public readonly string $observes,
        public readonly string $registration,
        public readonly array $hooks,
    ) {
    }
}
