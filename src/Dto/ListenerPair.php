<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * An (event, listener-class, method) binding emitted by listener-pair visitors.
 *
 * @internal
 */
final readonly class ListenerPair
{
    public function __construct(
        public string $event,
        public string $listener,
        public string $method,
    ) {}
}
