<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Phase 4 — populates the reverse `dispatched_from` / `sent_from` /
 * `notified_from` arrays on the target entry (event, job, mailable, or
 * notification) from each finalized dispatch site.
 *
 * @internal
 */
final class DispatchedFromPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        foreach ($context->dispatchSites as $site) {
            // Sites owned by a closure listener stay off the reverse arrays; a
            // route closure supplies its own origin label.
            $origin = is_string($site['closureOrigin'] ?? null) ? $site['closureOrigin'] : null;
            if (($site['inClosure'] ?? false) === true && $origin === null) {
                continue;
            }

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

            $target = $site[Field::TARGET->value] ?? null;
            $classFqcn = $site['classFqcn'] ?? null;
            $method = $site[Field::METHOD->value] ?? null;
            $file = $site[Field::FILE->value] ?? null;
            $line = $site[Field::LINE->value] ?? null;

            if ($origin === null && is_string($classFqcn) && is_string($method)) {
                $origin = $classFqcn.'::'.$method;
            }
            if (! is_string($target) || $origin === null) {
                continue;
            }
            if (! is_string($file) || ! is_int($line)) {
                continue;
            }

            $fqcnIndex = $context->index($section);
            if (! isset($fqcnIndex[$target])) {
                continue;
            }

            $payload = [
                Field::FILE->value => $file,
                Field::LINE->value => $line,
                Field::METHOD->value => $origin,
            ];

            // Only surface `mode` for non-plain dispatch forms; omitting it
            // otherwise keeps existing entries' JSON byte-identical.
            $mode = $site[Field::MODE->value] ?? null;
            if (is_string($mode)) {
                $payload[Field::MODE->value] = $mode;
            }

            // Only surface `overrides` when the site carried static modifiers;
            // omitting it otherwise keeps existing entries' JSON byte-identical.
            $overrides = $site[Field::OVERRIDES->value] ?? null;
            if (is_array($overrides) && $overrides !== []) {
                $payload[Field::OVERRIDES->value] = $overrides;
            }

            // Only surface `channels` when the site carried a static channel
            // filter (NOTIFICATION sites only); omitting it keeps existing
            // entries' JSON byte-identical.
            $channels = $site[Field::CHANNELS->value] ?? null;
            if (is_array($channels) && $channels !== []) {
                $payload[Field::CHANNELS->value] = $channels;
            }

            $context->appendToEntry($section, $fqcnIndex[$target], $fromField, $payload);
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
            DispatchKinds::EVENT => [Sections::EVENTS, Field::DISPATCHED_FROM->value],
            DispatchKinds::JOB => [Sections::JOBS, Field::DISPATCHED_FROM->value],
            DispatchKinds::MAILABLE => [Sections::MAILABLES, Field::SENT_FROM->value],
            DispatchKinds::NOTIFICATION => [Sections::NOTIFICATIONS, Field::NOTIFIED_FROM->value],
            DispatchKinds::AMBIGUOUS => null,
        };
    }
}
