<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** @internal */
final readonly class SubscriberClassRecord
{
    /**
     * @param  list<ListenerHandle>  $handles
     * @param  list<ClosurePairRecord>  $closureHandles
     * @param  list<ListenerPair>  $foreignPairs
     */
    public function __construct(
        public string $fqcn,
        public int $line,
        public bool $queued,
        public array $handles,
        public array $closureHandles,
        public array $foreignPairs,
    ) {}
}
