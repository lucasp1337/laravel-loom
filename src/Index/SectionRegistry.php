<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Single ordered source of truth for top-level index sections.
 *
 * The descriptor order below IS the output body order emitted by
 * {@see Index::toArray()}. The `stats` block is derived from the same list,
 * filtered to descriptors flagged `inStats`. Adding a section requires only a
 * new {@see Sections} case plus one entry here.
 */
final class SectionRegistry
{
    /**
     * Ordered section descriptors, in output body order.
     *
     * @var list<array{section: Sections, inStats: bool}>
     */
    public const DESCRIPTORS = [
        ['section' => Sections::EVENTS, 'inStats' => true],
        ['section' => Sections::MODEL_EVENTS, 'inStats' => false],
        ['section' => Sections::LISTENERS, 'inStats' => true],
        ['section' => Sections::OBSERVERS, 'inStats' => true],
        ['section' => Sections::JOBS, 'inStats' => true],
        ['section' => Sections::UNRESOLVED_DISPATCHES, 'inStats' => true],
        ['section' => Sections::CLOSURE_LISTENERS, 'inStats' => true],
        ['section' => Sections::SCHEDULED, 'inStats' => true],
        ['section' => Sections::MAILABLES, 'inStats' => true],
        ['section' => Sections::NOTIFICATIONS, 'inStats' => true],
    ];

    /**
     * Section names in output body order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (array $descriptor): string => $descriptor['section']->value,
            self::DESCRIPTORS,
        );
    }

    /**
     * Section names that contribute to the `stats` block, in order.
     *
     * @return list<string>
     */
    public static function statsNames(): array
    {
        $names = [];
        foreach (self::DESCRIPTORS as $descriptor) {
            if ($descriptor['inStats']) {
                $names[] = $descriptor['section']->value;
            }
        }

        return $names;
    }
}
