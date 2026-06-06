<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Index\CrossLink\AmbiguousDisambiguationPhase;
use Lucasp\Loom\Index\CrossLink\ClosureDispatchAttributionPhase;
use Lucasp\Loom\Index\CrossLink\CrossLinkContext;
use Lucasp\Loom\Index\CrossLink\CrossLinkPhase;
use Lucasp\Loom\Index\CrossLink\DispatchAttributionPhase;
use Lucasp\Loom\Index\CrossLink\DispatchedFromPhase;
use Lucasp\Loom\Index\CrossLink\HandledByPhase;
use Lucasp\Loom\Index\CrossLink\SortPhase;

/**
 * Runs the cross-link pass: an ordered list of {@see CrossLinkPhase}s that
 * join the per-scanner sections into the relations the schema exposes
 * (handled_by, dispatches, dispatched_from, …).
 *
 * The default phase order matters — disambiguation must finalize a site's
 * kind before attribution and dispatched_from read it, and sorting runs last.
 */
final class CrossLinker
{
    /** @var list<CrossLinkPhase> */
    private array $phases;

    /**
     * @param  list<CrossLinkPhase>|null  $phases  defaults to the canonical pipeline
     */
    public function __construct(?array $phases = null)
    {
        $this->phases = $phases ?? [
            new HandledByPhase,
            new AmbiguousDisambiguationPhase,
            new DispatchAttributionPhase,
            new ClosureDispatchAttributionPhase,
            new DispatchedFromPhase,
            new SortPhase,
        ];
    }

    /**
     * Cross-link the merged sections and return them. `_dispatch_sites` is
     * read for attribution but never mutated into the returned sections.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function crossLink(array $sections): array
    {
        $context = $this->buildContext($sections);

        foreach ($this->phases as $phase) {
            $phase->apply($context);
        }

        return $context->sections;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function buildContext(array $sections): CrossLinkContext
    {
        /** @var array<int, array<string, mixed>> $dispatchSites */
        $dispatchSites = $sections['_dispatch_sites'] ?? [];

        // Observers are indexed separately — multiple entries per FQCN are allowed.
        $singleIndexes = [
            Sections::EVENTS->value => $this->indexByFqcn($sections[Sections::EVENTS->value]),
            Sections::LISTENERS->value => $this->indexByFqcn($sections[Sections::LISTENERS->value]),
            Sections::JOBS->value => $this->indexByFqcn($sections[Sections::JOBS->value]),
            Sections::MAILABLES->value => $this->indexByFqcn($sections[Sections::MAILABLES->value]),
            Sections::NOTIFICATIONS->value => $this->indexByFqcn($sections[Sections::NOTIFICATIONS->value]),
        ];
        $observerIndex = $this->indexByFqcnMulti($sections[Sections::OBSERVERS->value]);

        return new CrossLinkContext($sections, $dispatchSites, $singleIndexes, $observerIndex);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function indexByFqcn(array $entries): array
    {
        $index = [];
        foreach ($entries as $idx => $entry) {
            if (isset($entry[Field::FQCN->value]) && is_string($entry[Field::FQCN->value])) {
                $index[$entry[Field::FQCN->value]] = $idx;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, list<int>>
     */
    private function indexByFqcnMulti(array $entries): array
    {
        $index = [];
        foreach ($entries as $idx => $entry) {
            if (isset($entry[Field::FQCN->value]) && is_string($entry[Field::FQCN->value])) {
                $index[$entry[Field::FQCN->value]][] = $idx;
            }
        }

        return $index;
    }
}
