<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

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
    ) {
    }

    public function total(): int
    {
        return count($this->listeners) + count($this->closureListeners);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'listeners' => array_map(static fn (ListenerHandler $h): array => $h->toArray(), $this->listeners),
            'closure_listeners' => array_map(static fn (ClosureHandler $h): array => $h->toArray(), $this->closureListeners),
        ];
    }
}
