<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Listener;

final readonly class Orphans
{
    /**
     * @param  list<Event>  $events  neither dispatched nor handled
     * @param  list<Listener>  $idleListeners  handling no events
     */
    public function __construct(
        public array $events,
        public array $idleListeners,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orphan_events' => array_map(static fn (Event $e): array => [
                'fqcn' => $e->fqcn,
                'kind' => $e->kind,
                'file' => $e->file,
                'line' => $e->line,
            ], $this->events),
            'idle_listeners' => array_map(static fn (Listener $l): array => [
                'fqcn' => $l->fqcn,
                'file' => $l->file,
                'line' => $l->line,
            ], $this->idleListeners),
        ];
    }
}
