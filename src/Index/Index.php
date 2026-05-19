<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Immutable Loom index. Serializes to schema/loom-index.schema.json.
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
     * @param  array<int, array<string, mixed>>  $scheduled
     * @param  array<int, array<string, mixed>>  $mailables
     * @param  array<int, array<string, mixed>>  $notifications
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
        public readonly array $scheduled = [],
        public readonly array $mailables = [],
        public readonly array $notifications = [],
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
                'scheduled' => count($this->scheduled),
                'mailables' => count($this->mailables),
                'notifications' => count($this->notifications),
            ],
            'events' => $this->events,
            'model_events' => $this->modelEvents,
            'listeners' => $this->listeners,
            'observers' => $this->observers,
            'jobs' => $this->jobs,
            'unresolved_dispatches' => $this->unresolvedDispatches,
            'closure_listeners' => $this->closureListeners,
            'scheduled' => $this->scheduled,
            'mailables' => $this->mailables,
            'notifications' => $this->notifications,
        ];
    }
}
