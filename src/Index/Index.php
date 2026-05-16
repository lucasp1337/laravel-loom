<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Index;

/**
 * Immutable value object representing a complete Atlas index.
 *
 * Holds the merged output of every scanner plus top-level metadata. Serializes
 * to the canonical JSON shape defined in schema/atlas-index.schema.json.
 */
final class Index
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $modelEvents
     * @param  array<int, array<string, mixed>>  $listeners
     * @param  array<int, array<string, mixed>>  $observers
     * @param  array<int, array<string, mixed>>  $unresolvedDispatches
     */
    public function __construct(
        public readonly string $atlasVersion,
        public readonly string $scannedAt,
        public readonly string $laravelVersion,
        public readonly array $events = [],
        public readonly array $modelEvents = [],
        public readonly array $listeners = [],
        public readonly array $observers = [],
        public readonly array $unresolvedDispatches = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'atlas_version' => $this->atlasVersion,
            'scanned_at' => $this->scannedAt,
            'laravel_version' => $this->laravelVersion,
            'stats' => [
                'events' => count($this->events),
                'listeners' => count($this->listeners),
                'observers' => count($this->observers),
                'unresolved_dispatches' => count($this->unresolvedDispatches),
            ],
            'events' => $this->events,
            'model_events' => $this->modelEvents,
            'listeners' => $this->listeners,
            'observers' => $this->observers,
            'unresolved_dispatches' => $this->unresolvedDispatches,
        ];
    }
}
