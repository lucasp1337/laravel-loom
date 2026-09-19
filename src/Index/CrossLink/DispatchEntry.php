<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;

/**
 * Shared, stateless builder for the dispatch object the schema attaches to a
 * handler's `dispatches[]` — `{target, kind, confidence, file, line}`. The
 * attribution phases (class listeners/jobs/observers, routes, closure
 * listeners) all emit this shape via {@see self::forHandler()}.
 */
final class DispatchEntry
{
    /**
     * Build the dispatch payload for a `_dispatch_sites` entry, or null when
     * the site can't form a valid dispatch (kind still ambiguous/unknown, or
     * a missing/mis-typed target, file, or line). Callers skip on null.
     *
     * Only AMBIGUOUS and unknown kinds are rejected here; mailable and
     * notification sites are valid (they feed `sent_from` / `notified_from`).
     * Handler `dispatches[]` must use {@see self::forHandler()} instead.
     *
     * @param  array<string, mixed>  $site
     * @return array{target: string, kind: string, confidence: string, file: string, line: int}|null
     */
    public static function fromSite(array $site): ?array
    {
        $kind = $site['provisionalKind'] ?? null;
        $target = $site[Field::TARGET->value] ?? null;
        $file = $site[Field::FILE->value] ?? null;
        $line = $site[Field::LINE->value] ?? null;
        $confidence = $site[Field::CONFIDENCE->value] ?? 'high';

        if (! is_string($target) || ! is_string($file) || ! is_int($line)
            || ! is_string($kind) || DispatchKinds::tryFrom($kind) === DispatchKinds::AMBIGUOUS
            || DispatchKinds::tryFrom($kind) === null
            || ! is_string($confidence)
        ) {
            return null;
        }

        return [
            Field::TARGET->value => $target,
            Field::KIND->value => $kind,
            Field::CONFIDENCE->value => $confidence,
            Field::FILE->value => $file,
            Field::LINE->value => $line,
        ];
    }

    /**
     * Like {@see self::fromSite()} but restricted to the kinds a handler's
     * `dispatches[]` may carry per the schema (event|job).
     *
     * @param  array<string, mixed>  $site
     * @return array{target: string, kind: string, confidence: string, file: string, line: int}|null
     */
    public static function forHandler(array $site): ?array
    {
        $payload = self::fromSite($site);
        if ($payload === null) {
            return null;
        }

        $kind = DispatchKinds::tryFrom($payload[Field::KIND->value]);

        return $kind === DispatchKinds::EVENT || $kind === DispatchKinds::JOB ? $payload : null;
    }
}
