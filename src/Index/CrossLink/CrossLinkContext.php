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
 *
 * @internal
 */
final class CrossLinkContext
{
    /** Longest chain of helper methods between a handler method and a dispatch. */
    public const MAX_HELPER_HOPS = 3;

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<int, array<string, mixed>>  $dispatchSites
     * @param  array<string, array<string, int>>  $singleIndexes  FQCN→entry index, keyed by section value
     * @param  array<string, list<int>>  $observerIndex  FQCN→entry indexes (multiple entries per FQCN allowed)
     * @param  array<string, array<string, true>>  $listenerMethods  per-listener method set
     * @param  array<int, array<string, mixed>>  $callEdges  same-class calls (`classFqcn`, `caller`, `callee`)
     */
    public function __construct(
        public array $sections,
        public array $dispatchSites,
        public readonly array $singleIndexes,
        public readonly array $observerIndex,
        public array $listenerMethods = [],
        public readonly array $callEdges = [],
    ) {}

    /** @var array<string, list<string>>|null `Class::callee` → callers, built on first use */
    private ?array $callersByCallee = null;

    /**
     * The methods of a class from which a site in `$method` is reached through
     * same-class calls, the method itself first (empty `via`). Each other entry
     * names the calling method and the helper methods between it and the site,
     * in call order, ending with `$method`. Bounded by {@see self::MAX_HELPER_HOPS};
     * a call cycle ends the walk.
     *
     * @return list<array{method: string, via: list<string>}>
     */
    public function reachingMethods(string $class, string $method): array
    {
        $callers = $this->callersByCallee();

        $out = [['method' => $method, 'via' => []]];
        $queue = [['method' => $method, 'via' => [$method]]];
        $seen = [$method => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (count($current['via']) > self::MAX_HELPER_HOPS) {
                continue;
            }

            foreach ($callers[$class.'::'.$current['method']] ?? [] as $caller) {
                if (isset($seen[$caller])) {
                    continue;
                }
                $seen[$caller] = true;
                $out[] = ['method' => $caller, 'via' => $current['via']];
                $queue[] = ['method' => $caller, 'via' => [$caller, ...$current['via']]];
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function callersByCallee(): array
    {
        if ($this->callersByCallee !== null) {
            return $this->callersByCallee;
        }

        $map = [];
        foreach ($this->callEdges as $edge) {
            $class = $edge['classFqcn'] ?? null;
            $caller = $edge['caller'] ?? null;
            $callee = $edge['callee'] ?? null;
            if (is_string($class) && is_string($caller) && is_string($callee)) {
                $map[$class.'::'.$callee][] = $caller;
            }
        }

        return $this->callersByCallee = $map;
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
