<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Illuminate\Support\Arr;

/** @internal */
final readonly class MethodChain
{
    /**
     * @param  list<DispatchRef>  $dispatches
     * @param  list<EventChain>  $chains
     */
    public function __construct(
        public string $methodFqcn,
        public array $dispatches,
        public array $chains,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method_fqcn' => $this->methodFqcn,
            'dispatches' => Arr::map($this->dispatches, static fn (DispatchRef $d): array => $d->toArray()),
            'chains' => Arr::map($this->chains, static fn (EventChain $c): array => $c->toArray()),
        ];
    }
}
