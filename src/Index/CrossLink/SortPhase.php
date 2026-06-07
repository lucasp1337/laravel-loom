<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Support\Sorting;

/**
 * Phase 5 — sorts every cross-linked array into a deterministic order so the
 * emitted index is stable across runs.
 */
final class SortPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        foreach ($this->sortTable() as $section => $fields) {
            foreach ($context->sections[$section] as $idx => $entry) {
                foreach ($fields as $field => $keys) {
                    /** @var array<int, array<string, mixed>> $list */
                    $list = $entry[$field] ?? [];
                    usort($list, Sorting::byKeys($keys));
                    $context->sections[$section][$idx][$field] = $list;
                }
            }
        }
    }

    /**
     * Section value → field → sort keys.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function sortTable(): array
    {
        return [
            Sections::EVENTS->value => [
                Field::HANDLED_BY->value => [Field::LISTENER->value, Field::METHOD->value],
                Field::DISPATCHED_FROM->value => [Field::FILE->value, Field::LINE->value, Field::METHOD->value],
            ],
            Sections::LISTENERS->value => [
                Field::DISPATCHES->value => [Field::FILE->value, Field::LINE->value, Field::TARGET->value],
            ],
            Sections::CLOSURE_LISTENERS->value => [
                Field::DISPATCHES->value => [Field::FILE->value, Field::LINE->value, Field::TARGET->value],
            ],
            Sections::OBSERVERS->value => [
                Field::DISPATCHES->value => [Field::FILE->value, Field::LINE->value, Field::TARGET->value],
            ],
            Sections::JOBS->value => [
                Field::DISPATCHED_FROM->value => [Field::FILE->value, Field::LINE->value, Field::METHOD->value],
                Field::DISPATCHES->value => [Field::FILE->value, Field::LINE->value, Field::TARGET->value],
            ],
            Sections::MAILABLES->value => [
                Field::SENT_FROM->value => [Field::FILE->value, Field::LINE->value, Field::METHOD->value],
            ],
            Sections::NOTIFICATIONS->value => [
                Field::NOTIFIED_FROM->value => [Field::FILE->value, Field::LINE->value, Field::METHOD->value],
            ],
            Sections::ROUTES->value => [
                Field::DISPATCHES->value => [Field::FILE->value, Field::LINE->value, Field::TARGET->value],
            ],
        ];
    }
}
