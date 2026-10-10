<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Query\HandlerKind;

/**
 * A handler as listed in an impact report: `Class::method` for a listener,
 * `file:line` for a closure. Same `handler`/`handler_kind` pair as a chain edge.
 *
 * @internal
 */
final readonly class HandlerRef
{
    public function __construct(
        public string $handler,
        public HandlerKind $kind,
    ) {}

    /** @return array{handler: string, handler_kind: string} */
    public function toArray(): array
    {
        return ['handler' => $this->handler, 'handler_kind' => $this->kind->value];
    }
}
