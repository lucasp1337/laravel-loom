<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Index;

use JsonSchema\Validator;
use Lucasp\Atlas\Contracts\Scanner;
use Lucasp\Atlas\Scanners\Visitors\ObserverClassVisitor;
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
    public const ATLAS_VERSION = '0.1.0';

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
            'unresolved_dispatches' => [],
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
            atlasVersion: self::ATLAS_VERSION,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
            laravelVersion: $laravelVersion,
            events: $sections['events'],
            modelEvents: $sections['model_events'],
            listeners: $sections['listeners'],
            observers: $sections['observers'],
            unresolvedDispatches: $sections['unresolved_dispatches'],
        );
    }

    /**
     * Validate an index payload against schema/atlas-index.schema.json.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string> validation errors; empty when valid
     */
    public function validate(array $payload): array
    {
        $schemaPath = dirname(__DIR__, 2).'/schema/atlas-index.schema.json';
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
     *          enum) matches an observer.
     * Phase 5: events[*].dispatched_from from sites whose target is an
     *          event FQCN (kind=event after disambiguation).
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
        $eventIndex = [];
        foreach ($sections['events'] as $idx => $event) {
            if (isset($event['fqcn']) && is_string($event['fqcn'])) {
                $eventIndex[$event['fqcn']] = $idx;
            }
        }

        $listenerIndex = [];
        foreach ($sections['listeners'] as $idx => $listener) {
            if (isset($listener['fqcn']) && is_string($listener['fqcn'])) {
                $listenerIndex[$listener['fqcn']] = $idx;
            }
        }

        // Observers may have multiple entries per FQCN (one per observed model).
        /** @var array<string, array<int, int>> $observerIndex */
        $observerIndex = [];
        foreach ($sections['observers'] as $idx => $observer) {
            if (isset($observer['fqcn']) && is_string($observer['fqcn'])) {
                $observerIndex[$observer['fqcn']][] = $idx;
            }
        }

        // Phase 1: handled_by from listener.handles inversion.
        foreach ($sections['listeners'] as $listener) {
            $listenerFqcn = $listener['fqcn'] ?? null;
            $handles = $listener['handles'] ?? [];
            if (! is_string($listenerFqcn) || ! is_array($handles)) {
                continue;
            }

            foreach ($handles as $eventFqcn) {
                if (! is_string($eventFqcn)) {
                    continue;
                }
                if (! isset($eventIndex[$eventFqcn])) {
                    continue;
                }
                $eIdx = $eventIndex[$eventFqcn];
                /** @var array<int, string> $handledBy */
                $handledBy = $sections['events'][$eIdx]['handled_by'] ?? [];
                if (! in_array($listenerFqcn, $handledBy, true)) {
                    $handledBy[] = $listenerFqcn;
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

            // Listener dispatch attribution: method must be exactly "handle".
            if ($method === 'handle' && isset($listenerIndex[$classFqcn])) {
                $lIdx = $listenerIndex[$classFqcn];
                /** @var array<int, array<string, mixed>> $existing */
                $existing = $sections['listeners'][$lIdx]['dispatches'] ?? [];
                $existing[] = $entry;
                $sections['listeners'][$lIdx]['dispatches'] = $existing;
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

        // Phase 5: events[*].dispatched_from. Sites with classFqcn=null or
        // method=null are skipped because the schema requires a method string.
        foreach ($dispatchSites as $site) {
            $kind = $site['provisionalKind'] ?? null;
            if ($kind !== 'event') {
                continue;
            }

            $target = $site['target'] ?? null;
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site['method'] ?? null;
            $file = $site['file'] ?? null;
            $line = $site['line'] ?? null;

            if (! is_string($target) || ! isset($eventIndex[$target])) {
                continue;
            }
            if (! is_string($classFqcn) || ! is_string($method)) {
                continue;
            }
            if (! is_string($file) || ! is_int($line)) {
                continue;
            }

            $eIdx = $eventIndex[$target];
            /** @var array<int, array<string, mixed>> $existing */
            $existing = $sections['events'][$eIdx]['dispatched_from'] ?? [];
            $existing[] = [
                'file' => $file,
                'line' => $line,
                'method' => $classFqcn.'::'.$method,
            ];
            $sections['events'][$eIdx]['dispatched_from'] = $existing;
        }

        // Sort all cross-linked arrays for deterministic output.
        foreach ($sections['events'] as $idx => $event) {
            /** @var array<int, string> $handledBy */
            $handledBy = $event['handled_by'] ?? [];
            sort($handledBy);
            $sections['events'][$idx]['handled_by'] = $handledBy;

            /** @var array<int, array<string, mixed>> $dispatchedFrom */
            $dispatchedFrom = $event['dispatched_from'] ?? [];
            usort($dispatchedFrom, function (array $a, array $b): int {
                return [$a['file'] ?? '', $a['line'] ?? 0, $a['method'] ?? ''] <=>
                    [$b['file'] ?? '', $b['line'] ?? 0, $b['method'] ?? ''];
            });
            $sections['events'][$idx]['dispatched_from'] = $dispatchedFrom;
        }

        foreach ($sections['listeners'] as $idx => $listener) {
            /** @var array<int, array<string, mixed>> $dispatches */
            $dispatches = $listener['dispatches'] ?? [];
            usort($dispatches, function (array $a, array $b): int {
                return [$a['file'] ?? '', $a['line'] ?? 0, $a['target'] ?? ''] <=>
                    [$b['file'] ?? '', $b['line'] ?? 0, $b['target'] ?? ''];
            });
            $sections['listeners'][$idx]['dispatches'] = $dispatches;
        }

        foreach ($sections['observers'] as $idx => $observer) {
            /** @var array<int, array<string, mixed>> $dispatches */
            $dispatches = $observer['dispatches'] ?? [];
            usort($dispatches, function (array $a, array $b): int {
                return [$a['file'] ?? '', $a['line'] ?? 0, $a['target'] ?? ''] <=>
                    [$b['file'] ?? '', $b['line'] ?? 0, $b['target'] ?? ''];
            });
            $sections['observers'][$idx]['dispatches'] = $dispatches;
        }
    }
}
