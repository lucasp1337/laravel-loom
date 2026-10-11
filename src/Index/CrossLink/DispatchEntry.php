<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;

/**
 * Shared, stateless builder for the dispatch object the schema attaches to a
 * handler's `dispatches[]` — `{target, kind, confidence, file, line}`. The
 * attribution phases (class listeners/jobs/observers, routes, closure
 * listeners) all emit this shape via {@see self::forHandler()}.
 *
 * @internal
 */
final class DispatchEntry
{
    /**
     * Build the dispatch payload for a `_dispatch_sites` entry, or null when
     * the site can't form a valid dispatch (kind still ambiguous/unknown, or
     * a missing/mis-typed target, file, or line). Callers skip on null. Every
     * resolved kind is valid: event, job, mailable and notification.
     *
     * @param  array<string, mixed>  $site
     * @return array{target: string, kind: string, confidence: string, file: string, line: int}|null
     */
    public static function forHandler(array $site): ?array
    {
        $kind = $site['provisionalKind'] ?? null;
        $target = $site[Field::TARGET->value] ?? null;
        $file = $site[Field::FILE->value] ?? null;
        $line = $site[Field::LINE->value] ?? null;
        $confidence = $site[Field::CONFIDENCE->value] ?? Confidence::HIGH->value;

        if (! is_string($target) || ! is_string($file) || ! is_int($line)
            || ! is_string($kind) || ! is_string($confidence)
        ) {
            return null;
        }

        $resolved = DispatchKinds::tryFrom($kind);
        if ($resolved === null || $resolved === DispatchKinds::AMBIGUOUS) {
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
}
