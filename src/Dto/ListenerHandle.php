<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** An event => method binding on a listener or subscriber. */
final class ListenerHandle
{
    public function __construct(
        public readonly string $event,
        public readonly string $method,
    ) {}
}
