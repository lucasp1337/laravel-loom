<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Illuminate\Support\Arr;
use Lucasp\Loom\Index\DispatchKinds;

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

    /**
     * Distinct targets of one kind, dispatched directly or anywhere down the chains.
     *
     * @return list<string>
     */
    public function reached(DispatchKinds $kind): array
    {
        return array_values(collect(DispatchRef::targetsOf($this->dispatches, $kind))
            ->merge(collect($this->chains)->flatMap(static fn (EventChain $c): array => $c->reached($kind)))
            ->unique()
            ->all());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method_fqcn' => $this->methodFqcn,
            'dispatches' => Arr::map($this->dispatches, static fn (DispatchRef $d): array => $d->toArray()),
            'chains' => Arr::map($this->chains, static fn (EventChain $c): array => $c->toArray()),
            'mailables_reached' => $this->reached(DispatchKinds::MAILABLE),
            'notifications_reached' => $this->reached(DispatchKinds::NOTIFICATION),
        ];
    }
}
