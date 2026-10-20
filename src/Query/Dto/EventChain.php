<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Illuminate\Support\Arr;
use Lucasp\Loom\Index\DispatchKinds;

/**
 * Transitive handler/dispatch chain rooted at an event, bounded by depth.
 * Mailables and notifications are leaves: reached, never expanded.
 *
 * @internal
 */
final readonly class EventChain
{
    /**
     * @param  list<ChainEdge>  $edges
     * @param  list<string>  $eventsReached
     * @param  list<ChainCycle>  $cycles
     * @param  bool  $truncated  events remain unexpanded because the depth bound was hit
     */
    public function __construct(
        public string $root,
        public int $depth,
        public array $edges,
        public array $eventsReached,
        public array $cycles = [],
        public bool $truncated = false,
    ) {}

    /**
     * Distinct targets of one kind that the walked handlers dispatch.
     *
     * @return list<string>
     */
    public function reached(DispatchKinds $kind): array
    {
        return DispatchRef::targetsOf(collect($this->edges)->flatMap(static fn (ChainEdge $e): array => $e->dispatches), $kind);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'depth' => $this->depth,
            'edges' => Arr::map($this->edges, static fn (ChainEdge $e): array => $e->toArray()),
            'events_reached' => $this->eventsReached,
            'mailables_reached' => $this->reached(DispatchKinds::MAILABLE),
            'notifications_reached' => $this->reached(DispatchKinds::NOTIFICATION),
            'cycles' => Arr::map($this->cycles, static fn (ChainCycle $c): array => $c->toArray()),
            'truncated' => $this->truncated,
        ];
    }
}
