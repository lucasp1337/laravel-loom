<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Sections;

/**
 * Phase 4 — populates the reverse `dispatched_from` / `sent_from` /
 * `notified_from` arrays on the target entry (event, job, mailable, or
 * notification) from each finalized dispatch site.
 */
final class DispatchedFromPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        foreach ($context->dispatchSites as $site) {
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

            $fqcnIndex = $context->index($section);
            if (! isset($fqcnIndex[$target])) {
                continue;
            }

            $context->appendToEntry($section, $fqcnIndex[$target], $fromField, [
                'file' => $file,
                'line' => $line,
                'method' => $classFqcn.'::'.$method,
            ]);
        }
    }

    /**
     * Returns null for AMBIGUOUS (resolved in phase 2; shouldn't reach here).
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
}
