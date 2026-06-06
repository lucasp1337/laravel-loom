<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff;

use Closure;
use Lucasp\Loom\Diff\Spec\SectionDiffSpec;
use Lucasp\Loom\Diff\Spec\SubListSpec;
use Lucasp\Loom\Index\Sections;

/**
 * Single source of truth for how each index section is diffed: identity,
 * semantic fields, and sublists. The list order is the report order and
 * mirrors the {@see Sections} enum.
 */
final class DiffSpecRegistry
{
    private const SEP = "\x1F";

    private const NULL = "\0null";

    /**
     * @return list<SectionDiffSpec>
     */
    public static function specs(): array
    {
        return [
            new SectionDiffSpec(
                Sections::EVENTS->value,
                self::byKeys(['fqcn']),
                ['kind', 'file', 'line'],
                [
                    new SubListSpec('handled_by', self::memberByKeys(['listener', 'method'])),
                    new SubListSpec('dispatched_from', self::dispatchSiteMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::MODEL_EVENTS->value,
                self::byKeys(['id']),
                [],
                [
                    new SubListSpec('handled_by', self::scalarMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::LISTENERS->value,
                self::byKeys(['fqcn']),
                ['file', 'line', 'registration', 'queued'],
                [
                    new SubListSpec('handles', self::memberByKeys(['event', 'method'])),
                    new SubListSpec('dispatches', self::memberByKeys(['target', 'kind', 'file', 'line'])),
                ],
            ),
            new SectionDiffSpec(
                Sections::OBSERVERS->value,
                self::byKeys(['fqcn', 'observes']),
                ['file', 'line', 'registration'],
                [
                    new SubListSpec('hooks', self::scalarMember()),
                    new SubListSpec('dispatches', self::memberByKeys(['target', 'kind', 'file', 'line'])),
                ],
            ),
            new SectionDiffSpec(
                Sections::JOBS->value,
                self::byKeys(['fqcn']),
                ['file', 'line', 'queued', 'queue_config'],
                [
                    new SubListSpec('dispatched_from', self::dispatchSiteMember()),
                    new SubListSpec('dispatches', self::memberByKeys(['target', 'kind', 'file', 'line'])),
                ],
            ),
            new SectionDiffSpec(
                Sections::UNRESOLVED_DISPATCHES->value,
                self::byKeys(['file', 'line', 'expression']),
                ['reason'],
            ),
            new SectionDiffSpec(
                Sections::CLOSURE_LISTENERS->value,
                self::byKeys(['file', 'line', 'event', 'registration']),
                [],
            ),
            new SectionDiffSpec(
                Sections::SCHEDULED->value,
                self::byKeys(['file', 'line', 'kind', 'target']),
                ['cron', 'timezone', 'without_overlapping', 'on_one_server', 'run_in_background'],
                [
                    new SubListSpec('constraints', self::scalarMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::MAILABLES->value,
                self::byKeys(['fqcn']),
                ['file', 'line', 'queued', 'queue_config'],
                [
                    new SubListSpec('sent_from', self::dispatchSiteMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::NOTIFICATIONS->value,
                self::byKeys(['fqcn']),
                ['file', 'line', 'queued', 'queue_config', 'channels', 'channels_dynamic'],
                [
                    new SubListSpec('notified_from', self::dispatchSiteMember(includeChannels: true)),
                ],
            ),
        ];
    }

    /**
     * Identity from a fixed tuple of entry keys.
     *
     * @param  list<string>  $keys
     * @return Closure(array<string,mixed>): string
     */
    private static function byKeys(array $keys): Closure
    {
        return static function (array $entry) use ($keys): string {
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::scalar($entry[$key] ?? null);
            }

            return implode(self::SEP, $parts);
        };
    }

    /**
     * Sublist member identity from a fixed tuple of member keys.
     *
     * @param  list<string>  $keys
     * @return Closure(array<string,mixed>): string
     */
    private static function memberByKeys(array $keys): Closure
    {
        return self::byKeys($keys);
    }

    /**
     * Sublist whose members are plain strings (handled_by, hooks, constraints).
     *
     * @return Closure(array<string,mixed>): string
     */
    private static function scalarMember(): Closure
    {
        return static fn (array $member): string => self::scalar($member['value'] ?? null);
    }

    /**
     * Dispatch-site member identity: (file, line, method) plus normalized
     * overrides, and optionally normalized channels for notifications. This
     * makes an override/channel change surface as a remove+add of the member.
     *
     * @return Closure(array<string,mixed>): string
     */
    private static function dispatchSiteMember(bool $includeChannels = false): Closure
    {
        return static function (array $member) use ($includeChannels): string {
            $parts = [
                self::scalar($member['file'] ?? null),
                self::scalar($member['line'] ?? null),
                self::scalar($member['method'] ?? null),
                self::canonical($member['overrides'] ?? []),
            ];
            if ($includeChannels) {
                $parts[] = self::canonical($member['channels'] ?? []);
            }

            return implode(self::SEP, $parts);
        };
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return self::NULL;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return self::canonical($value);
    }

    /**
     * Stable string encoding of a structured value for identity comparison.
     * Absent optional keys are normalized away by the callers passing `[]`.
     */
    private static function canonical(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
