<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

use Illuminate\Support\Arr;

/**
 * Single ordered source of truth for top-level index sections.
 *
 * The descriptor order below IS the output body order emitted by
 * {@see Index::toArray()}, and the `stats` block carries one count per section.
 * Descriptors flagged `listed` are the ones the UI shows in its sidebar and
 * dashboard. Adding a section requires only a new {@see Sections} case plus
 * one entry here.
 *
 * @internal
 */
final class SectionRegistry
{
    /**
     * Ordered section descriptors, in output body order.
     *
     * @var list<array{section: Sections, listed: bool}>
     */
    public const DESCRIPTORS = [
        ['section' => Sections::EVENTS, 'listed' => true],
        ['section' => Sections::MODEL_EVENTS, 'listed' => false],
        ['section' => Sections::LISTENERS, 'listed' => true],
        ['section' => Sections::OBSERVERS, 'listed' => true],
        ['section' => Sections::JOBS, 'listed' => true],
        ['section' => Sections::UNRESOLVED_DISPATCHES, 'listed' => true],
        ['section' => Sections::CLOSURE_LISTENERS, 'listed' => true],
        ['section' => Sections::SCHEDULED_TASKS, 'listed' => true],
        ['section' => Sections::ROUTES, 'listed' => true],
        ['section' => Sections::MAILABLES, 'listed' => true],
        ['section' => Sections::NOTIFICATIONS, 'listed' => true],
    ];

    /**
     * Section names in output body order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(Arr::map(self::DESCRIPTORS, static fn (array $descriptor): string => $descriptor['section']->value));
    }

    /**
     * Section names shown in the UI sidebar and dashboard, in order.
     *
     * @return list<string>
     */
    public static function listedNames(): array
    {
        $names = [];
        foreach (self::DESCRIPTORS as $descriptor) {
            if ($descriptor['listed']) {
                $names[] = $descriptor['section']->value;
            }
        }

        return $names;
    }
}
