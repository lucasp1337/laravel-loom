<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ListenerRegistration;

/**
 * Mutable scanner-internal accumulator while merging listener registrations
 * from auto-discovery, $listen, Event::listen, and subscribers.
 *
 * Mutable because the merge pass updates fields incrementally as it folds in
 * each registration source. The final readonly DTO is `ListenerEntry`, built
 * at emit time.
 */
final class ListenerLocation
{
    /** @var array<string, ListenerHandle> indexed by "event::method" */
    public array $handles;

    public function __construct(
        public ?string $file,
        public ?int $line,
        public bool $queued,
        public ListenerRegistration $registration,
    ) {
        $this->handles = [];
    }
}
