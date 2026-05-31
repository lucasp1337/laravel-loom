<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Sections;

/**
 * Mutable state threaded through the cross-link phases. Holds the merged
 * sections (mutated in place as each phase runs), the dispatch sites, and the
 * FQCN→entry lookups {@see CrossLinker} precomputes once up front.
 *
 * `listenerMethods` is a scratchpad: {@see HandledByPhase} populates it and
 * {@see DispatchAttributionPhase} consumes it.
 */
final class CrossLinkContext
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<int, array<string, mixed>>  $dispatchSites
     * @param  array<string, array<string, int>>  $singleIndexes  FQCN→entry index, keyed by section value
     * @param  array<string, list<int>>  $observerIndex  FQCN→entry indexes (multiple entries per FQCN allowed)
     * @param  array<string, array<string, true>>  $listenerMethods  per-listener method set
     */
    public function __construct(
        public array $sections,
        public array $dispatchSites,
        public readonly array $singleIndexes,
        public readonly array $observerIndex,
        public array $listenerMethods = [],
    ) {
    }

    /**
     * FQCN→entry index for a single-entry-per-FQCN section.
     *
     * @return array<string, int>
     */
    public function index(Sections $section): array
    {
        return $this->singleIndexes[$section->value] ?? [];
    }

    /**
     * Append a payload to a list-valued field on a section entry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function appendToEntry(Sections $section, int $idx, string $field, array $payload): void
    {
        /** @var array<int, array<string, mixed>> $existing */
        $existing = $this->sections[$section->value][$idx][$field] ?? [];
        $existing[] = $payload;
        $this->sections[$section->value][$idx][$field] = $existing;
    }
}
