<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/** @internal */
final readonly class MethodChain
{
    /**
     * @param  list<DispatchRef>  $dispatches
     * @param  list<EventChain>  $chains
     */
    public function __construct(
        public string $method,
        public array $dispatches,
        public array $chains,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'dispatches' => array_map(static fn (DispatchRef $d): array => $d->toArray(), $this->dispatches),
            'chains' => array_map(static fn (EventChain $c): array => $c->toArray(), $this->chains),
        ];
    }
}
