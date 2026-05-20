<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** An (event, listener-class, method) binding emitted by listener-pair visitors. */
final class ListenerPair
{
    public function __construct(
        public readonly string $event,
        public readonly string $listener,
        public readonly string $method,
    ) {
    }
}
