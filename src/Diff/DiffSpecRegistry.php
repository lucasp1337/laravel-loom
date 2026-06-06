<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff;

use Closure;
use Lucasp\Loom\Diff\Result\SubListDelta;
use Lucasp\Loom\Diff\Spec\SectionDiffSpec;
use Lucasp\Loom\Diff\Spec\SubListSpec;
use Lucasp\Loom\Index\Field;
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
                Sections::EVENTS,
                self::byKeys([Field::FQCN]),
                [Field::KIND, Field::FILE, Field::LINE],
                [
                    new SubListSpec(Field::HANDLED_BY, self::memberByKeys([Field::LISTENER, Field::METHOD])),
                    new SubListSpec(Field::DISPATCHED_FROM, self::dispatchSiteMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::MODEL_EVENTS,
                self::byKeys([Field::ID]),
                [],
                [
                    new SubListSpec(Field::HANDLED_BY, self::scalarMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::LISTENERS,
                self::byKeys([Field::FQCN]),
                [Field::FILE, Field::LINE, Field::REGISTRATION, Field::QUEUED],
                [
                    new SubListSpec(Field::HANDLES, self::memberByKeys([Field::EVENT, Field::METHOD])),
                    new SubListSpec(Field::DISPATCHES, self::memberByKeys([Field::TARGET, Field::KIND, Field::FILE, Field::LINE])),
                ],
            ),
            new SectionDiffSpec(
                Sections::OBSERVERS,
                self::byKeys([Field::FQCN, Field::OBSERVES]),
                [Field::FILE, Field::LINE, Field::REGISTRATION],
                [
                    new SubListSpec(Field::HOOKS, self::scalarMember()),
                    new SubListSpec(Field::DISPATCHES, self::memberByKeys([Field::TARGET, Field::KIND, Field::FILE, Field::LINE])),
                ],
            ),
            new SectionDiffSpec(
                Sections::JOBS,
                self::byKeys([Field::FQCN]),
                [Field::FILE, Field::LINE, Field::QUEUED, Field::QUEUE_CONFIG],
                [
                    new SubListSpec(Field::DISPATCHED_FROM, self::dispatchSiteMember()),
                    new SubListSpec(Field::DISPATCHES, self::memberByKeys([Field::TARGET, Field::KIND, Field::FILE, Field::LINE])),
                ],
            ),
            new SectionDiffSpec(
                Sections::UNRESOLVED_DISPATCHES,
                self::byKeys([Field::FILE, Field::LINE, Field::EXPRESSION]),
                [Field::REASON],
            ),
            new SectionDiffSpec(
                Sections::CLOSURE_LISTENERS,
                self::byKeys([Field::FILE, Field::LINE, Field::EVENT, Field::REGISTRATION]),
                [],
            ),
            new SectionDiffSpec(
                Sections::SCHEDULED,
                self::byKeys([Field::FILE, Field::LINE, Field::KIND, Field::TARGET]),
                [Field::CRON, Field::TIMEZONE, Field::WITHOUT_OVERLAPPING, Field::ON_ONE_SERVER, Field::RUN_IN_BACKGROUND],
                [
                    new SubListSpec(Field::CONSTRAINTS, self::scalarMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::MAILABLES,
                self::byKeys([Field::FQCN]),
                [Field::FILE, Field::LINE, Field::QUEUED, Field::QUEUE_CONFIG],
                [
                    new SubListSpec(Field::SENT_FROM, self::dispatchSiteMember()),
                ],
            ),
            new SectionDiffSpec(
                Sections::NOTIFICATIONS,
                self::byKeys([Field::FQCN]),
                [Field::FILE, Field::LINE, Field::QUEUED, Field::QUEUE_CONFIG, Field::CHANNELS, Field::CHANNELS_DYNAMIC],
                [
                    new SubListSpec(Field::NOTIFIED_FROM, self::dispatchSiteMember(includeChannels: true)),
                ],
            ),
        ];
    }

    /**
     * Identity from a fixed tuple of entry keys.
     *
     * @param  list<Field>  $keys
     * @return Closure(array<string,mixed>): string
     */
    private static function byKeys(array $keys): Closure
    {
        return static function (array $entry) use ($keys): string {
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::scalar($entry[$key->value] ?? null);
            }

            return implode(self::SEP, $parts);
        };
    }

    /**
     * Sublist member identity from a fixed tuple of member keys.
     *
     * @param  list<Field>  $keys
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
        return static fn (array $member): string => self::scalar($member[SubListDelta::SCALAR_MEMBER_KEY] ?? null);
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
                self::scalar($member[Field::FILE->value] ?? null),
                self::scalar($member[Field::LINE->value] ?? null),
                self::scalar($member[Field::METHOD->value] ?? null),
                self::canonical($member[Field::OVERRIDES->value] ?? []),
            ];
            if ($includeChannels) {
                $parts[] = self::canonical($member[Field::CHANNELS->value] ?? []);
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
