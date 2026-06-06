<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
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
            // Closure-internal sites belong to a closure listener, not to the
            // enclosing class method; ClosureDispatchAttributionPhase owns them.
            if (($site['inClosure'] ?? false) === true) {
                continue;
            }

            $payload = DispatchEntry::fromSite($site);
            if ($payload === null) {
                continue;
            }

            // Routing keys live on the site, not in the shared payload.
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site[Field::METHOD->value] ?? null;
            if (! is_string($classFqcn) || ! is_string($method)) {
                continue;
            }

            if (isset($listenerIndex[$classFqcn], $context->listenerMethods[$classFqcn][$method])) {
                $context->appendToEntry(Sections::LISTENERS, $listenerIndex[$classFqcn], Field::DISPATCHES->value, $payload);
            }

            if ($method === 'handle' && isset($jobIndex[$classFqcn])) {
                $context->appendToEntry(Sections::JOBS, $jobIndex[$classFqcn], Field::DISPATCHES->value, $payload);
            }

            if (isset($observerHooks[$method], $context->observerIndex[$classFqcn])) {
                foreach ($context->observerIndex[$classFqcn] as $oIdx) {
                    $context->appendToEntry(Sections::OBSERVERS, $oIdx, Field::DISPATCHES->value, $payload);
                }
            }
        }
    }
}
