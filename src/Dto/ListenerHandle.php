<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * An event => method binding on a listener or subscriber.
 *
 * @internal
 */
final readonly class ListenerHandle
{
    public function __construct(
        public string $event,
        public string $method,
    ) {}
}
