<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Attributes each dispatch site to the closure listener whose source span
 * encloses it. Closure listeners have no FQCN to key on (multiple can live in
 * one file), so attribution is purely positional: a site belongs to a closure
 * when they share a file and the site's line falls within the closure's span.
 *
 * Runs after {@see AmbiguousDisambiguationPhase} so `provisionalKind` is
 * already resolved, and after {@see DispatchAttributionPhase} for ordering
 * consistency with the class-handler attribution.
 */
final class ClosureDispatchAttributionPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $closures = $context->sections[Sections::CLOSURE_LISTENERS->value] ?? [];

        foreach ($closures as $index => $closure) {
            $file = $closure[Field::FILE->value] ?? null;
            $start = $closure[Field::LINE->value] ?? null;
            $end = $closure[Field::END_LINE->value] ?? null;

            if (! is_string($file) || ! is_int($start) || ! is_int($end)) {
                continue;
            }

            foreach ($context->dispatchSites as $site) {
                // Only closure-internal (tagged) sites belong here; class-method
                // sites are owned by DispatchAttributionPhase. Without this, a
                // class-method dispatch whose line happens to fall inside a
                // closure listener's span would be mis-attributed.
                if (($site['inClosure'] ?? false) !== true) {
                    continue;
                }

                $siteFile = $site[Field::FILE->value] ?? null;
                $siteLine = $site[Field::LINE->value] ?? null;
                if (! is_string($siteFile) || ! is_int($siteLine)) {
                    continue;
                }

                // Inclusive bounds: a dispatch sitting exactly on the closure's
                // opening or closing line still belongs to it.
                if ($siteFile !== $file || $siteLine < $start || $siteLine > $end) {
                    continue;
                }

                $payload = DispatchEntry::fromSite($site);
                if ($payload === null) {
                    continue;
                }

                // Closure dispatches[] only carries event|job per the schema;
                // drop mailable/notification (which the shared builder allows).
                $kind = DispatchKinds::tryFrom($payload[Field::KIND->value]);
                if ($kind !== DispatchKinds::EVENT && $kind !== DispatchKinds::JOB) {
                    continue;
                }

                // Nested closures: a site inside an inner anonymous function
                // also falls within its enclosing closure's span, so it is
                // appended to every matching closure (it falls inside any
                // enclosing closure's line span). Overlap only arises via nesting.
                $context->appendToEntry(Sections::CLOSURE_LISTENERS, $index, Field::DISPATCHES->value, $payload);
            }
        }
    }
}
