<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Dto;

/**
 * A handler dispatching an event the walk already reached (a true cycle, or a
 * diamond re-converging). The walk does not descend into it again.
 */
final readonly class ChainCycle
{
    public function __construct(
        public string $fromHandler,
        public string $backToEvent,
    ) {
    }

    /** @return array{from_handler: string, back_to_event: string} */
    public function toArray(): array
    {
        return ['from_handler' => $this->fromHandler, 'back_to_event' => $this->backToEvent];
    }
}
