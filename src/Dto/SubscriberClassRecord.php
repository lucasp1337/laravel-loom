<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

final class SubscriberClassRecord
{
    /**
     * @param  list<ListenerHandle>  $handles
     * @param  list<ClosurePairRecord>  $closureHandles
     * @param  list<ListenerPair>  $foreignPairs
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly bool $queued,
        public readonly array $handles,
        public readonly array $closureHandles,
        public readonly array $foreignPairs,
    ) {
    }
}
