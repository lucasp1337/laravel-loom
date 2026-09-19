<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class ListenerHandler
{
    public function __construct(
        public string $listener,
        public string $method,
        public bool $queued,
    ) {
    }

    /** @return array{listener: string, method: string, queued: bool} */
    public function toArray(): array
    {
        return ['listener' => $this->listener, 'method' => $this->method, 'queued' => $this->queued];
    }
}
