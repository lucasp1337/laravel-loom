<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Scanners\Visitors\ObserverClassVisitor;

/**
 * Phase 3 — attributes each dispatch site to its enclosing handler's
 * `dispatches[]`: a listener method that handles an event, a job's `handle()`,
 * or an observer's Eloquent hook.
 */
final class DispatchAttributionPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $observerHooks = array_flip(ObserverClassVisitor::HOOKS);
        $listenerIndex = $context->index(Sections::LISTENERS);
        $jobIndex = $context->index(Sections::JOBS);

        foreach ($context->dispatchSites as $site) {
            $entry = $this->dispatchEntryFromSite($site);
            if ($entry === null) {
                continue;
            }
            $classFqcn = $entry['classFqcn'];
            $method = $entry['method'];
            $payload = $entry['payload'];

            if (isset($listenerIndex[$classFqcn], $context->listenerMethods[$classFqcn][$method])) {
                $context->appendToEntry(Sections::LISTENERS, $listenerIndex[$classFqcn], 'dispatches', $payload);
            }

            if ($method === 'handle' && isset($jobIndex[$classFqcn])) {
                $context->appendToEntry(Sections::JOBS, $jobIndex[$classFqcn], 'dispatches', $payload);
            }

            if (isset($observerHooks[$method], $context->observerIndex[$classFqcn])) {
                foreach ($context->observerIndex[$classFqcn] as $oIdx) {
                    $context->appendToEntry(Sections::OBSERVERS, $oIdx, 'dispatches', $payload);
                }
            }
        }
    }

    /**
     * Returns null when the site is shaped wrong (missing class/target, still
     * ambiguous, etc).
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
}
