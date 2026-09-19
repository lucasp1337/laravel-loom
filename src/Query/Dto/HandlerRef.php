<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Query\HandlerKind;

/** A handler as listed in an impact report; closures use `file:line` and method `closure`. */
final readonly class HandlerRef
{
    public function __construct(
        public string $listener,
        public string $method,
        public HandlerKind $kind,
    ) {
    }

    /** @return array{listener: string, method: string, kind: string} */
    public function toArray(): array
    {
        return ['listener' => $this->listener, 'method' => $this->method, 'kind' => $this->kind->value];
    }
}
