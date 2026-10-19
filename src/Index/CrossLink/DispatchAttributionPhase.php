<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Attributes each dispatch site to its enclosing handler's
 * `dispatches[]`: a listener method that handles an event, a job's `handle()`,
 * or an observer's Eloquent hook. Needs the listener method sets from
 * {@see HandledByPhase}, resolved kinds from {@see AmbiguousDisambiguationPhase}
 * and the cleared closure tags from {@see ClosureOwnershipPhase}.
 *
 * A site is attributed when its enclosing method is the handler method, and
 * also when the handler method reaches that method through same-class calls
 * (`$this->helper()`, `self::helper()`, `static::helper()`; up to
 * {@see CrossLinkContext::MAX_HELPER_HOPS} helpers). Such a dispatch carries
 * `via` (the helpers, in call order) and `confidence: medium`. Helpers inherited
 * from a parent class or trait, calls on other objects and callables are not
 * followed. An observer method that is not one of the entry's `hooks` gets none.
 *
 * @internal
 */
final class DispatchAttributionPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $listenerIndex = $context->index(Sections::LISTENERS);
        $jobIndex = $context->index(Sections::JOBS);

        foreach ($context->dispatchSites as $site) {
            // Closure-internal sites belong to a closure listener, not to the
            // enclosing class method; ClosureDispatchAttributionPhase owns them.
            if (($site['inClosure'] ?? false) === true) {
                continue;
            }

            $payload = DispatchEntry::forHandler($site);
            if ($payload === null) {
                continue;
            }

            // Routing keys live on the site, not in the shared payload.
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site[Field::METHOD->value] ?? null;
            if (! is_string($classFqcn) || ! is_string($method)) {
                continue;
            }

            foreach ($context->reachingMethods($classFqcn, $method) as $reach) {
                $entryPayload = DispatchEntry::viaHelpers($payload, $reach['via']);
                $entryMethod = $reach['method'];

                if (isset($listenerIndex[$classFqcn], $context->listenerMethods[$classFqcn][$entryMethod])) {
                    $this->appendOnce($context, Sections::LISTENERS, $listenerIndex[$classFqcn], $entryPayload);
                }

                if ($entryMethod === 'handle' && isset($jobIndex[$classFqcn])) {
                    $this->appendOnce($context, Sections::JOBS, $jobIndex[$classFqcn], $entryPayload);
                }

                foreach ($context->observerIndex[$classFqcn] ?? [] as $oIdx) {
                    $hooks = $context->sections[Sections::OBSERVERS->value][$oIdx][Field::HOOKS->value] ?? [];
                    if (is_array($hooks) && collect($hooks)->containsStrict($entryMethod)) {
                        $this->appendOnce($context, Sections::OBSERVERS, $oIdx, $entryPayload);
                    }
                }
            }
        }
    }

    /**
     * Append unless the entry already lists the same dispatch: the first
     * (direct, then shortest) path to a site wins over a longer one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function appendOnce(CrossLinkContext $context, Sections $section, int $idx, array $payload): void
    {
        $existing = $context->sections[$section->value][$idx][Field::DISPATCHES->value] ?? [];
        foreach ($existing as $dispatch) {
            if (is_array($dispatch)
                && ($dispatch[Field::TARGET->value] ?? null) === $payload[Field::TARGET->value]
                && ($dispatch[Field::KIND->value] ?? null) === $payload[Field::KIND->value]
                && ($dispatch[Field::FILE->value] ?? null) === $payload[Field::FILE->value]
                && ($dispatch[Field::LINE->value] ?? null) === $payload[Field::LINE->value]
            ) {
                return;
            }
        }

        $context->appendToEntry($section, $idx, Field::DISPATCHES->value, $payload);
    }
}
