<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Query\HandlerKind;

/**
 * One handler of one event in a chain, with what it dispatches. `file`/`line`
 * locate the handler and are not part of the legacy array shape.
 *
 * @internal
 */
final readonly class ChainEdge
{
    /** @param  list<DispatchRef>  $dispatches */
    public function __construct(
        public string $event,
        public string $handler,
        public HandlerKind $handlerKind,
        public array $dispatches,
        public int $level,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'handler' => $this->handler,
            'handler_kind' => $this->handlerKind->value,
            'dispatches' => array_map(static fn (DispatchRef $d): array => $d->toArray(), $this->dispatches),
        ];
    }
}
