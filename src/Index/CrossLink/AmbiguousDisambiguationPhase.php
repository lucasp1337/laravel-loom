<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Sections;

/**
 * Phase 2 — `X::dispatch()` is ambiguous (the Dispatchable trait covers both
 * events and jobs). Resolve each ambiguous site to `event` if its target is a
 * known event, else `job`, mutating the dispatch sites for later phases.
 */
final class AmbiguousDisambiguationPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $eventIndex = $context->index(Sections::EVENTS);

        foreach ($context->dispatchSites as $i => $site) {
            if (($site['provisionalKind'] ?? null) !== DispatchKinds::AMBIGUOUS->value) {
                continue;
            }
            $target = $site['target'] ?? null;
            if (! is_string($target)) {
                continue;
            }
            $context->dispatchSites[$i]['provisionalKind'] = isset($eventIndex[$target])
                ? DispatchKinds::EVENT->value
                : DispatchKinds::JOB->value;
        }
    }
}
