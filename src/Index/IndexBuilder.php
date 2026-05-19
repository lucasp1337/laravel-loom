<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;
use Lucasp\Loom\Support\Sorting;
use RuntimeException;

/**
 * Orchestrates scanners, merges their output, cross-links relationships,
 * and produces the final Index. Schema validation lives on
 * `SchemaValidator` — `validate()` here is a thin delegate.
 *
 * Cross-linking happens in five named phases (see `crossLink()`); each
 * phase has its own method so the high-level flow is readable at a
 * glance. Section names and dispatch kinds are string-backed enums
 * (`Sections`, `DispatchKinds`) — use `->value` when indexing into the
 * raw sections array.
 */
class IndexBuilder
{
    public const LOOM_VERSION = '0.2.0';

    /** @var array<int, Scanner> */
    private array $scanners = [];

    private SchemaValidator $validator;

    public function __construct(?SchemaValidator $validator = null)
    {
        $this->validator = $validator ?? new SchemaValidator;
    }

    public function register(Scanner $scanner): void
    {
        $this->scanners[] = $scanner;
    }

    /**
     * Run every registered scanner against $appRoot and assemble the Index.
     *
     * Internal, underscore-prefixed sections (e.g. `_dispatch_sites`) flow
     * through the merge but are stripped before the Index is constructed.
     */
    public function build(string $appRoot, string $laravelVersion): Index
    {
        $sections = $this->initialSections();

        foreach ($this->scanners as $scanner) {
            foreach ($scanner->scan($appRoot) as $section => $entries) {
                if (str_starts_with($section, '_')) {
                    $sections[$section] ??= [];
                    $sections[$section] = array_merge($sections[$section], $entries);

                    continue;
                }

                if (! array_key_exists($section, $sections)) {
                    throw new RuntimeException("Scanner returned unknown section: {$section}");
                }
                $sections[$section] = array_merge($sections[$section], $entries);
            }
        }

        $this->crossLink($sections);
        $this->stripInternalSections($sections);

        return $this->buildIndex($sections, $laravelVersion);
    }

    /**
     * Validate an index payload against the canonical schema. Delegates to
     * `SchemaValidator`; kept on the builder for call-site ergonomics.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        return $this->validator->validate($payload);
    }

    /**
     * Initial sections array — one entry per public section, all empty.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function initialSections(): array
    {
        $sections = [];
        foreach (Sections::cases() as $section) {
            $sections[$section->value] = [];
        }

        return $sections;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function stripInternalSections(array &$sections): void
    {
        foreach (array_keys($sections) as $key) {
            if (str_starts_with($key, '_')) {
                unset($sections[$key]);
            }
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function buildIndex(array $sections, string $laravelVersion): Index
    {
        return new Index(
            loomVersion: self::LOOM_VERSION,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
            laravelVersion: $laravelVersion,
            events: $sections[Sections::EVENTS->value],
            modelEvents: $sections[Sections::MODEL_EVENTS->value],
            listeners: $sections[Sections::LISTENERS->value],
            observers: $sections[Sections::OBSERVERS->value],
            jobs: $sections[Sections::JOBS->value],
            unresolvedDispatches: $sections[Sections::UNRESOLVED_DISPATCHES->value],
            closureListeners: $sections[Sections::CLOSURE_LISTENERS->value],
            scheduled: $sections[Sections::SCHEDULED->value],
            mailables: $sections[Sections::MAILABLES->value],
            notifications: $sections[Sections::NOTIFICATIONS->value],
        );
    }

    /**
     * Five-phase cross-link pass. Mutates $sections in place.
     *
     *  1. handled_by inversion (events ← listeners.handles)
     *  2. ambiguous-site disambiguation (Dispatchable form)
     *  3. dispatch attribution (listeners + jobs + observers dispatches[])
     *  4. dispatched_from joins (events / jobs / mailables / notifications)
     *  5. deterministic sort of every cross-linked array
     *
     * Phase 2 runs before 3-5 so downstream consumers read the
     * disambiguated kind.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function crossLink(array &$sections): void
    {
        /** @var array<int, array<string, mixed>> $dispatchSites */
        $dispatchSites = $sections['_dispatch_sites'] ?? [];

        // Single-FQCN indexes keyed by section value — uniform shape so
        // downstream phases can look up by section without PHPStan losing
        // type information. Observers indexed separately because they can
        // have multiple entries per FQCN.
        $singleIndexes = [
            Sections::EVENTS->value => $this->indexByFqcn($sections[Sections::EVENTS->value]),
            Sections::LISTENERS->value => $this->indexByFqcn($sections[Sections::LISTENERS->value]),
            Sections::JOBS->value => $this->indexByFqcn($sections[Sections::JOBS->value]),
            Sections::MAILABLES->value => $this->indexByFqcn($sections[Sections::MAILABLES->value]),
            Sections::NOTIFICATIONS->value => $this->indexByFqcn($sections[Sections::NOTIFICATIONS->value]),
        ];
        $observerIndex = $this->indexByFqcnMulti($sections[Sections::OBSERVERS->value]);

        $listenerMethods = $this->applyHandledBy($sections, $singleIndexes[Sections::EVENTS->value]);
        $this->disambiguateAmbiguousSites($dispatchSites, $singleIndexes[Sections::EVENTS->value]);
        $this->applyDispatchAttribution($sections, $dispatchSites, $singleIndexes, $observerIndex, $listenerMethods);
        $this->applyDispatchedFrom($sections, $dispatchSites, $singleIndexes);
        $this->sortCrossLinkedArrays($sections);
    }

    /**
     * Phase 1: events[*].handled_by ← listeners[*].handles inversion.
     *
     * Also builds and returns a per-listener method set used by phase 3
     * for dispatch attribution (avoids re-scanning listeners[]).
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<string, int>  $eventIndex
     * @return array<string, array<string, true>>
     */
    private function applyHandledBy(array &$sections, array $eventIndex): array
    {
        /** @var array<string, array<string, true>> $listenerMethods */
        $listenerMethods = [];

        foreach ($sections[Sections::LISTENERS->value] as $listener) {
            $listenerFqcn = $listener['fqcn'] ?? null;
            $handles = $listener['handles'] ?? [];
            if (! is_string($listenerFqcn) || ! is_array($handles)) {
                continue;
            }

            foreach ($handles as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $eventFqcn = $pair['event'] ?? null;
                $method = $pair['method'] ?? null;
                if (! is_string($eventFqcn) || ! is_string($method)) {
                    continue;
                }

                $listenerMethods[$listenerFqcn][$method] = true;

                if (! isset($eventIndex[$eventFqcn])) {
                    continue;
                }
                $this->appendHandledBy($sections, $eventIndex[$eventFqcn], $listenerFqcn, $method);
            }
        }

        return $listenerMethods;
    }

    /**
     * Append a (listener, method) pair to events[$idx].handled_by if it
     * isn't already there.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function appendHandledBy(array &$sections, int $eIdx, string $listenerFqcn, string $method): void
    {
        /** @var array<int, array{listener: string, method: string}> $handledBy */
        $handledBy = $sections[Sections::EVENTS->value][$eIdx]['handled_by'] ?? [];

        foreach ($handledBy as $existing) {
            if ($existing['listener'] === $listenerFqcn && $existing['method'] === $method) {
                return;
            }
        }

        $handledBy[] = ['listener' => $listenerFqcn, 'method' => $method];
        $sections[Sections::EVENTS->value][$eIdx]['handled_by'] = $handledBy;
    }

    /**
     * Phase 2: resolve `provisionalKind: ambiguous` sites to either
     * `event` or `job`. Ambiguous sites come from the `X::dispatch()`
     * shape where the trait could be `Dispatchable` (event or job).
     * Disambiguate by checking whether the target is a known event.
     *
     * @param  array<int, array<string, mixed>>  $dispatchSites
     * @param  array<string, int>  $eventIndex
     */
    private function disambiguateAmbiguousSites(array &$dispatchSites, array $eventIndex): void
    {
        foreach ($dispatchSites as $i => $site) {
            if (($site['provisionalKind'] ?? null) !== DispatchKinds::AMBIGUOUS->value) {
                continue;
            }
            $target = $site['target'] ?? null;
            if (! is_string($target)) {
                continue;
            }
            $dispatchSites[$i]['provisionalKind'] = isset($eventIndex[$target])
                ? DispatchKinds::EVENT->value
                : DispatchKinds::JOB->value;
        }
    }

    /**
     * Phases 3 & 4: append per-site dispatch attribution to
     * listeners[*].dispatches, jobs[*].dispatches, and
     * observers[*].dispatches.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<int, array<string, mixed>>  $dispatchSites
     * @param  array<string, array<string, int>>  $singleIndexes
     * @param  array<string, list<int>>  $observerIndex
     * @param  array<string, array<string, true>>  $listenerMethods
     */
    private function applyDispatchAttribution(array &$sections, array $dispatchSites, array $singleIndexes, array $observerIndex, array $listenerMethods): void
    {
        $observerHooks = array_flip(ObserverClassVisitor::HOOKS);

        foreach ($dispatchSites as $site) {
            $entry = $this->dispatchEntryFromSite($site);
            if ($entry === null) {
                continue;
            }
            $classFqcn = $entry['classFqcn'];
            $method = $entry['method'];
            $payload = $entry['payload'];

            $listenerIndex = $singleIndexes[Sections::LISTENERS->value];
            if (isset($listenerIndex[$classFqcn], $listenerMethods[$classFqcn][$method])) {
                $this->appendToEntry($sections, Sections::LISTENERS, $listenerIndex[$classFqcn], 'dispatches', $payload);
            }

            $jobIndex = $singleIndexes[Sections::JOBS->value];
            if ($method === 'handle' && isset($jobIndex[$classFqcn])) {
                $this->appendToEntry($sections, Sections::JOBS, $jobIndex[$classFqcn], 'dispatches', $payload);
            }

            if (isset($observerHooks[$method], $observerIndex[$classFqcn])) {
                foreach ($observerIndex[$classFqcn] as $oIdx) {
                    $this->appendToEntry($sections, Sections::OBSERVERS, $oIdx, 'dispatches', $payload);
                }
            }
        }
    }

    /**
     * Phase 5: events[*].dispatched_from, jobs[*].dispatched_from,
     * mailables[*].sent_from, notifications[*].notified_from. The kind
     * → (section, from-field) mapping is exhaustive via `match()` so
     * PHPStan flags any kind that's not handled.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<int, array<string, mixed>>  $dispatchSites
     * @param  array<string, array<string, int>>  $singleIndexes
     */
    private function applyDispatchedFrom(array &$sections, array $dispatchSites, array $singleIndexes): void
    {
        foreach ($dispatchSites as $site) {
            $rawKind = $site['provisionalKind'] ?? null;
            if (! is_string($rawKind)) {
                continue;
            }
            $kind = DispatchKinds::tryFrom($rawKind);
            if ($kind === null) {
                continue;
            }

            $mapping = $this->dispatchedFromMapping($kind);
            if ($mapping === null) {
                continue;
            }
            [$section, $fromField] = $mapping;

            $target = $site['target'] ?? null;
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site['method'] ?? null;
            $file = $site['file'] ?? null;
            $line = $site['line'] ?? null;

            if (! is_string($target) || ! is_string($classFqcn) || ! is_string($method)) {
                continue;
            }
            if (! is_string($file) || ! is_int($line)) {
                continue;
            }

            $fqcnIndex = $singleIndexes[$section->value];
            if (! isset($fqcnIndex[$target])) {
                continue;
            }

            $this->appendToEntry($sections, $section, $fqcnIndex[$target], $fromField, [
                'file' => $file,
                'line' => $line,
                'method' => $classFqcn.'::'.$method,
            ]);
        }
    }

    /**
     * Map a finalised dispatch kind to its (section, from-field) target.
     * Returns null for `AMBIGUOUS` (should never reach phase 5; phase 2
     * resolves it). Exhaustive over `DispatchKinds`.
     *
     * @return array{Sections, string}|null
     */
    private function dispatchedFromMapping(DispatchKinds $kind): ?array
    {
        return match ($kind) {
            DispatchKinds::EVENT => [Sections::EVENTS, 'dispatched_from'],
            DispatchKinds::JOB => [Sections::JOBS, 'dispatched_from'],
            DispatchKinds::MAILABLE => [Sections::MAILABLES, 'sent_from'],
            DispatchKinds::NOTIFICATION => [Sections::NOTIFICATIONS, 'notified_from'],
            DispatchKinds::AMBIGUOUS => null,
        };
    }

    /**
     * Final pass: sort every cross-linked array deterministically.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function sortCrossLinkedArrays(array &$sections): void
    {
        $sortTable = [
            Sections::EVENTS->value => [
                'handled_by' => ['listener', 'method'],
                'dispatched_from' => ['file', 'line', 'method'],
            ],
            Sections::LISTENERS->value => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::OBSERVERS->value => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::JOBS->value => [
                'dispatched_from' => ['file', 'line', 'method'],
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::MAILABLES->value => [
                'sent_from' => ['file', 'line', 'method'],
            ],
            Sections::NOTIFICATIONS->value => [
                'notified_from' => ['file', 'line', 'method'],
            ],
        ];

        foreach ($sortTable as $section => $fields) {
            foreach ($sections[$section] as $idx => $entry) {
                foreach ($fields as $field => $keys) {
                    /** @var array<int, array<string, mixed>> $list */
                    $list = $entry[$field] ?? [];
                    usort($list, Sorting::byKeys($keys));
                    $sections[$section][$idx][$field] = $list;
                }
            }
        }
    }

    /**
     * Extract the listener/job/observer attribution payload from a
     * dispatch site. Returns null when the site is shaped wrong for
     * attribution (missing class/target, still ambiguous, etc).
     *
     * @param  array<string, mixed>  $site
     * @return array{classFqcn: string, method: string, payload: array<string, mixed>}|null
     */
    private function dispatchEntryFromSite(array $site): ?array
    {
        $classFqcn = $site['classFqcn'] ?? null;
        $method = $site['method'] ?? null;
        $kind = $site['provisionalKind'] ?? null;
        $target = $site['target'] ?? null;
        $file = $site['file'] ?? null;
        $line = $site['line'] ?? null;
        $confidence = $site['confidence'] ?? 'high';

        if (! is_string($classFqcn) || ! is_string($target)
            || ! is_string($file) || ! is_int($line)
            || ! is_string($kind) || $kind === DispatchKinds::AMBIGUOUS->value
            || ! is_string($method)
        ) {
            return null;
        }

        return [
            'classFqcn' => $classFqcn,
            'method' => $method,
            'payload' => [
                'target' => $target,
                'kind' => $kind,
                'confidence' => $confidence,
                'file' => $file,
                'line' => $line,
            ],
        ];
    }

    /**
     * Append $payload to $sections[$section->value][$idx][$field],
     * initialising the array on first write.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $payload
     */
    private function appendToEntry(array &$sections, Sections $section, int $idx, string $field, array $payload): void
    {
        /** @var array<int, array<string, mixed>> $existing */
        $existing = $sections[$section->value][$idx][$field] ?? [];
        $existing[] = $payload;
        $sections[$section->value][$idx][$field] = $existing;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function indexByFqcn(array $entries): array
    {
        $index = [];
        foreach ($entries as $idx => $entry) {
            if (isset($entry['fqcn']) && is_string($entry['fqcn'])) {
                $index[$entry['fqcn']] = $idx;
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
            if (isset($entry['fqcn']) && is_string($entry['fqcn'])) {
                $index[$entry['fqcn']][] = $idx;
            }
        }

        return $index;
    }
}
