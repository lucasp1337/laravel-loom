<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Index\UnresolvedReason;

/**
 * Enforces the invariant that no `dispatches[]` entry names a target without a
 * row in its section. A site whose finalized kind has no row for its target
 * (an action class that is neither a job nor under `Jobs/`, a vendor event, a
 * mailable outside the scan paths) is removed from the attribution input and
 * listed in `unresolved_dispatches` with reason `target_not_indexed`, so the
 * site stays visible instead of dangling.
 *
 * A site already reported as `class_not_found` (no file declares the class)
 * is dropped here without a second entry: `class_not_found` means no file,
 * `target_not_indexed` means a file exists but no row, so one site never gets both.
 *
 * Needs the kinds {@see AmbiguousDisambiguationPhase} finalizes, and runs
 * before any attribution or `dispatched_from` phase reads the sites.
 *
 * @internal
 */
final class UnindexedTargetPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        $kept = [];
        $reported = [];
        $notFound = $this->classNotFoundKeys($context);

        foreach ($context->dispatchSites as $site) {
            $section = $this->sectionFor($site);
            $target = $site[Field::TARGET->value] ?? null;

            // Not a resolved kind or no target to check: later phases skip it themselves
            if ($section === null || ! is_string($target) || isset($context->index($section)[$target])) {
                $kept[] = $site;

                continue;
            }

            $file = $site[Field::FILE->value] ?? null;
            $line = $site[Field::LINE->value] ?? null;
            if (! is_string($file) || ! is_int($line)) {
                $kept[] = $site;

                continue;
            }

            // Already listed as class_not_found: drop the site, keep one entry
            if (isset($notFound[$file.':'.$line.':'.$target])) {
                continue;
            }

            $expression = is_string($site[Field::EXPRESSION->value] ?? null) ? $site[Field::EXPRESSION->value] : $target;
            $key = $file.':'.$line.':'.$expression;
            if (isset($reported[$key])) {
                continue;
            }
            $reported[$key] = true;

            $context->sections[Sections::UNRESOLVED_DISPATCHES->value][] = [
                Field::FILE->value => $file,
                Field::LINE->value => $line,
                Field::EXPRESSION->value => $expression,
                Field::REASON->value => UnresolvedReason::TARGET_NOT_INDEXED->value,
            ];
        }

        $context->dispatchSites = $kept;
    }

    /**
     * `file:line:Fqcn` of every site the class discovery listed as `class_not_found`.
     *
     * @return array<string, true>
     */
    private function classNotFoundKeys(CrossLinkContext $context): array
    {
        $keys = [];
        foreach ($context->sections[Sections::UNRESOLVED_DISPATCHES->value] ?? [] as $entry) {
            if (($entry[Field::REASON->value] ?? null) !== UnresolvedReason::CLASS_NOT_FOUND->value) {
                continue;
            }
            $expression = $entry[Field::EXPRESSION->value] ?? null;
            if (is_string($expression) && str_ends_with($expression, '::class')) {
                $keys[($entry[Field::FILE->value] ?? '').':'.($entry[Field::LINE->value] ?? '').':'.substr($expression, 0, -7)] = true;
            }
        }

        return $keys;
    }

    /**
     * The section whose row the site's target must have, or null for a kind
     * that is not final.
     *
     * @param  array<string, mixed>  $site
     */
    private function sectionFor(array $site): ?Sections
    {
        $kind = DispatchKinds::tryFrom(is_string($site['provisionalKind'] ?? null) ? $site['provisionalKind'] : '');

        return match ($kind) {
            DispatchKinds::EVENT => Sections::EVENTS,
            DispatchKinds::JOB => Sections::JOBS,
            DispatchKinds::MAILABLE => Sections::MAILABLES,
            DispatchKinds::NOTIFICATION => Sections::NOTIFICATIONS,
            DispatchKinds::AMBIGUOUS, null => null,
        };
    }
}
