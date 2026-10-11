<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/** @internal */
final readonly class ListenerEntry
{
    /**
     * @param  list<ListenerHandle>  $handles
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public array $handles,
        public ListenerRegistration $registration,
        public bool $queued,
    ) {}
}
