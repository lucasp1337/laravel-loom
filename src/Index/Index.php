<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Immutable value object representing a complete Loom index.
 *
 * Holds the merged output of every scanner plus top-level metadata. Serializes
 * to the canonical JSON shape defined in schema/loom-index.schema.json.
 */
final class Index
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $modelEvents
     * @param  array<int, array<string, mixed>>  $listeners
     * @param  array<int, array<string, mixed>>  $observers
     * @param  array<int, array<string, mixed>>  $jobs
     * @param  array<int, array<string, mixed>>  $unresolvedDispatches
     * @param  array<int, array<string, mixed>>  $closureListeners
     */
    public function __construct(
        public readonly string $loomVersion,
        public readonly string $scannedAt,
        public readonly string $laravelVersion,
        public readonly array $events = [],
        public readonly array $modelEvents = [],
        public readonly array $listeners = [],
        public readonly array $observers = [],
        public readonly array $jobs = [],
        public readonly array $unresolvedDispatches = [],
        public readonly array $closureListeners = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'loom_version' => $this->loomVersion,
            'scanned_at' => $this->scannedAt,
            'laravel_version' => $this->laravelVersion,
            'stats' => [
                'events' => count($this->events),
                'listeners' => count($this->listeners),
                'observers' => count($this->observers),
                'jobs' => count($this->jobs),
                'unresolved_dispatches' => count($this->unresolvedDispatches),
                'closure_listeners' => count($this->closureListeners),
            ],
            'events' => $this->events,
            'model_events' => $this->modelEvents,
            'listeners' => $this->listeners,
            'observers' => $this->observers,
            'jobs' => $this->jobs,
            'unresolved_dispatches' => $this->unresolvedDispatches,
            'closure_listeners' => $this->closureListeners,
        ];
    }
}
