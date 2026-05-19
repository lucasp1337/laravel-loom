<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use JsonSchema\Validator;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;
use RuntimeException;

/**
 * Orchestrates scanners, merges their output, cross-links relationships,
 * validates against the canonical schema, and produces the final Index.
 *
 * Scanners are registered in deterministic order. v0.1 ships with four:
 * EventScanner, ListenerScanner, ObserverScanner, DispatchScanner.
 */
class IndexBuilder
{
    public const LOOM_VERSION = '0.2.0';

    /** @var array<int, Scanner> */
    private array $scanners = [];

    public function register(Scanner $scanner): void
    {
        $this->scanners[] = $scanner;
    }

    /**
     * Run every registered scanner against $appRoot and assemble the Index.
     *
     * Cross-linking (events ↔ listeners, listener/observer dispatches,
     * event dispatched_from) happens after all scanners run. Internal,
     * underscore-prefixed sections (e.g. `_dispatch_sites`) flow through
     * the merge but are stripped before the Index is constructed and
     * before schema validation runs.
     */
    public function build(string $appRoot, string $laravelVersion): Index
    {
        /** @var array<string, array<int, array<string, mixed>>> $sections */
        $sections = [
            'events' => [],
            'listeners' => [],
            'observers' => [],
            'model_events' => [],
            'jobs' => [],
            'unresolved_dispatches' => [],
            'closure_listeners' => [],
            'scheduled' => [],
            'mailables' => [],
            'notifications' => [],
        ];

        foreach ($this->scanners as $scanner) {
            foreach ($scanner->scan($appRoot) as $section => $entries) {
                if (str_starts_with($section, '_')) {
                    // Internal section — initialise on first sight, then merge.
                    if (! array_key_exists($section, $sections)) {
                        $sections[$section] = [];
                    }
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

        // Strip internal sections before constructing the Index value object.
        foreach (array_keys($sections) as $key) {
            if (str_starts_with($key, '_')) {
                unset($sections[$key]);
            }
        }

        return new Index(
            loomVersion: self::LOOM_VERSION,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
            laravelVersion: $laravelVersion,
            events: $sections['events'],
            modelEvents: $sections['model_events'],
            listeners: $sections['listeners'],
            observers: $sections['observers'],
            jobs: $sections['jobs'],
            unresolvedDispatches: $sections['unresolved_dispatches'],
            closureListeners: $sections['closure_listeners'],
            scheduled: $sections['scheduled'],
            mailables: $sections['mailables'],
            notifications: $sections['notifications'],
        );
    }

    /**
     * Validate an index payload against schema/loom-index.schema.json.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        $schemaPath = dirname(__DIR__, 2).'/schema/loom-index.schema.json';
        if (! is_file($schemaPath)) {
            throw new RuntimeException("Schema not found at {$schemaPath}");
        }

        $validator = new Validator;
        $data = json_decode((string) json_encode($payload));
        $validator->validate($data, (object) ['$ref' => 'file://'.$schemaPath]);

        if ($validator->isValid()) {
            return [];
        }

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = sprintf('[%s] %s', $error['property'] ?? '', $error['message'] ?? '');
        }

        return $errors;
    }

    /**
     * Five-phase cross-link pass. Mutates $sections in place.
     *
     * Phase 1: events[*].handled_by ← listeners[*].handles inversion.
     * Phase 2: disambiguate _dispatch_sites whose provisionalKind is
     *          'ambiguous' (Dispatchable form) against events[].
     * Phase 3: listeners[*].dispatches from sites whose (class, method=handle)
     *          matches a listener.
     * Phase 4: observers[*].dispatches from sites whose (class, method∈hook
     *          enum) matches an observer. Also populates jobs[*].dispatches
     *          from sites whose (class, method=handle) matches a job.
     * Phase 5: events[*].dispatched_from, jobs[*].dispatched_from,
     *          mailables[*].sent_from, notifications[*].notified_from
     *          from sites whose target matches a known event/job/mailable/
     *          notification FQCN (kind after disambiguation).
     *
     * Disambiguation runs before phases 3/4/5 so every consumer reads the
     * final kind.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function crossLink(array &$sections): void
    {
        /** @var array<int, array<string, mixed>> $dispatchSites */
        $dispatchSites = $sections['_dispatch_sites'] ?? [];

        // Index lookups by FQCN for O(1) joins.
        $eventIndex = $this->indexByFqcn($sections['events']);
        $listenerIndex = $this->indexByFqcn($sections['listeners']);
        $jobIndex = $this->indexByFqcn($sections['jobs']);
        $mailableIndex = $this->indexByFqcn($sections['mailables']);
        $notificationIndex = $this->indexByFqcn($sections['notifications']);
        // Observers may have multiple entries per FQCN (one per observed model).
        $observerIndex = $this->indexByFqcnMulti($sections['observers']);

        // Phase 1: handled_by from listener.handles inversion.
        // Build per-listener method set for Phase 3 dispatch attribution while we're here.
        /** @var array<string, array<string, true>> $listenerMethods */
        $listenerMethods = [];

        foreach ($sections['listeners'] as $listener) {
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
                $eIdx = $eventIndex[$eventFqcn];
                /** @var array<int, array{listener: string, method: string}> $handledBy */
                $handledBy = $sections['events'][$eIdx]['handled_by'] ?? [];

                $alreadyPresent = false;
                foreach ($handledBy as $existing) {
                    if ($existing['listener'] === $listenerFqcn
                        && $existing['method'] === $method
                    ) {
                        $alreadyPresent = true;
                        break;
                    }
                }
                if (! $alreadyPresent) {
                    $handledBy[] = ['listener' => $listenerFqcn, 'method' => $method];
                }
                $sections['events'][$eIdx]['handled_by'] = $handledBy;
            }
        }

        // Phase 2: disambiguate ambiguous (Dispatchable) sites.
        foreach ($dispatchSites as $i => $site) {
            if (($site['provisionalKind'] ?? null) !== 'ambiguous') {
                continue;
            }
            $target = $site['target'] ?? null;
            if (! is_string($target)) {
                continue;
            }
            $dispatchSites[$i]['provisionalKind'] = isset($eventIndex[$target]) ? 'event' : 'job';
        }

        $observerHooks = array_flip(ObserverClassVisitor::HOOKS);

        // Phases 3 & 4: append to listeners[*].dispatches and observers[*].dispatches.
        foreach ($dispatchSites as $site) {
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site['method'] ?? null;
            $kind = $site['provisionalKind'] ?? null;
            $target = $site['target'] ?? null;
            $file = $site['file'] ?? null;
            $line = $site['line'] ?? null;
            $confidence = $site['confidence'] ?? 'high';

            if (! is_string($classFqcn) || ! is_string($target)
                || ! is_string($file) || ! is_int($line)
                || ! is_string($kind) || $kind === 'ambiguous'
            ) {
                continue;
            }

            $entry = [
                'target' => $target,
                'kind' => $kind,
                'confidence' => $confidence,
                'file' => $file,
                'line' => $line,
            ];

            // Listener dispatch attribution: enclosing method must be one of the
            // listener's registered handler methods (handle, handleFoo, etc.).
            if (is_string($method)
                && isset($listenerIndex[$classFqcn])
                && isset($listenerMethods[$classFqcn][$method])
            ) {
                $lIdx = $listenerIndex[$classFqcn];
                /** @var array<int, array<string, mixed>> $existing */
                $existing = $sections['listeners'][$lIdx]['dispatches'] ?? [];
                $existing[] = $entry;
                $sections['listeners'][$lIdx]['dispatches'] = $existing;
            }

            // Job dispatch attribution: enclosing class must be a known job,
            // enclosing method must be exactly `handle` (v1 strict gate —
            // utility-method dispatches are a documented gap).
            if (is_string($method) && $method === 'handle' && isset($jobIndex[$classFqcn])) {
                $jIdx = $jobIndex[$classFqcn];
                /** @var array<int, array<string, mixed>> $existing */
                $existing = $sections['jobs'][$jIdx]['dispatches'] ?? [];
                $existing[] = $entry;
                $sections['jobs'][$jIdx]['dispatches'] = $existing;
            }

            // Observer dispatch attribution: method must be a canonical hook.
            if (is_string($method) && isset($observerHooks[$method]) && isset($observerIndex[$classFqcn])) {
                foreach ($observerIndex[$classFqcn] as $oIdx) {
                    /** @var array<int, array<string, mixed>> $existing */
                    $existing = $sections['observers'][$oIdx]['dispatches'] ?? [];
                    $existing[] = $entry;
                    $sections['observers'][$oIdx]['dispatches'] = $existing;
                }
            }
        }

        // Phase 5: events[*].dispatched_from, jobs[*].dispatched_from,
        // mailables[*].sent_from, notifications[*].notified_from.
        // One join shape — one loop driven by the (kind → section, fqcn-index,
        // from-field) table. Sites with classFqcn/method null are skipped
        // because the shared dispatchSite shape requires a method string.
        //
        // @var array<string, array{0: string, 1: array<string, int>, 2: string}> $joinTable
        $joinTable = [
            'event' => ['events', $eventIndex, 'dispatched_from'],
            'job' => ['jobs', $jobIndex, 'dispatched_from'],
            'mailable' => ['mailables', $mailableIndex, 'sent_from'],
            'notification' => ['notifications', $notificationIndex, 'notified_from'],
        ];

        foreach ($dispatchSites as $site) {
            $kind = $site['provisionalKind'] ?? null;
            if (! is_string($kind) || ! isset($joinTable[$kind])) {
                continue;
            }

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

            [$section, $fqcnIndex, $fromField] = $joinTable[$kind];
            if (! isset($fqcnIndex[$target])) {
                continue;
            }

            $idx = $fqcnIndex[$target];
            /** @var array<int, array<string, mixed>> $existing */
            $existing = $sections[$section][$idx][$fromField] ?? [];
            $existing[] = [
                'file' => $file,
                'line' => $line,
                'method' => $classFqcn.'::'.$method,
            ];
            $sections[$section][$idx][$fromField] = $existing;
        }

        // Sort all cross-linked arrays for deterministic output. Per-section
        // table of (sectionName → list of fields to sort with their comparator
        // tuple keys) — each $field is sorted by (file, line, <key>) for
        // dispatch-site shapes and (listener, method) for handled_by.
        $sortTable = [
            'events' => [
                'handled_by' => ['listener', 'method'],
                'dispatched_from' => ['file', 'line', 'method'],
            ],
            'listeners' => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            'observers' => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            'jobs' => [
                'dispatched_from' => ['file', 'line', 'method'],
                'dispatches' => ['file', 'line', 'target'],
            ],
            'mailables' => [
                'sent_from' => ['file', 'line', 'method'],
            ],
            'notifications' => [
                'notified_from' => ['file', 'line', 'method'],
            ],
        ];

        foreach ($sortTable as $section => $fields) {
            foreach ($sections[$section] as $idx => $entry) {
                foreach ($fields as $field => $keys) {
                    /** @var array<int, array<string, mixed>> $list */
                    $list = $entry[$field] ?? [];
                    usort($list, $this->makeComparator($keys));
                    $sections[$section][$idx][$field] = $list;
                }
            }
        }
    }

    /**
     * Build a deterministic comparator over a tuple of array keys. Missing
     * keys are coerced to '' (string keys) or 0 (numeric keys via file/line
     * convention) — usort can't tolerate mixed-type comparisons, so coerce
     * to string by default and special-case `line` to int. This matches the
     * pre-refactor inline comparators byte-for-byte.
     *
     * @param  list<string>  $keys
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function makeComparator(array $keys): callable
    {
        return function (array $a, array $b) use ($keys): int {
            $aTuple = [];
            $bTuple = [];
            foreach ($keys as $key) {
                $default = $key === 'line' ? 0 : '';
                $aTuple[] = $a[$key] ?? $default;
                $bTuple[] = $b[$key] ?? $default;
            }

            return $aTuple <=> $bTuple;
        };
    }

    /**
     * Build an FQCN-to-array-index map from a section's entries. Skips
     * entries whose `fqcn` field is missing or not a string. One entry
     * per FQCN — last write wins (no collisions expected; FQCNs are
     * unique per section).
     *
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
     * Multi-valued variant: maps each FQCN to the list of indexes that
     * carry it. Used by `observers[]` where a single class can be observed
     * against multiple models, producing several entries per FQCN.
     *
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
