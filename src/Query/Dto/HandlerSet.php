<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

use Illuminate\Support\Arr;

/** @internal */
final readonly class HandlerSet
{
    /**
     * @param  list<ListenerHandler>  $listeners
     * @param  list<ClosureHandler>  $closureListeners
     */
    public function __construct(
        public string $event,
        public array $listeners,
        public array $closureListeners,
    ) {}

    public function count(): int
    {
        return count($this->listeners) + count($this->closureListeners);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'count' => $this->count(),
            'listeners' => Arr::map($this->listeners, static fn (ListenerHandler $h): array => $h->toArray()),
            'closure_listeners' => Arr::map($this->closureListeners, static fn (ClosureHandler $h): array => $h->toArray()),
        ];
    }
}
