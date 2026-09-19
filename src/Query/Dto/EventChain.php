<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/**
 * Transitive handler/dispatch chain rooted at an event, bounded by depth.
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'depth' => $this->depth,
            'edges' => array_map(static fn (ChainEdge $e): array => $e->toArray(), $this->edges),
            'events_reached' => $this->eventsReached,
            'cycles' => array_map(static fn (ChainCycle $c): array => $c->toArray(), $this->cycles),
            'truncated' => $this->truncated,
        ];
    }
}
