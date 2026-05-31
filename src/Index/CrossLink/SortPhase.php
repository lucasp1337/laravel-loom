<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

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
                'handled_by' => ['listener', 'method'],
                'dispatched_from' => ['file', 'line', 'method'],
            ],
            Sections::LISTENERS->value => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::CLOSURE_LISTENERS->value => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::OBSERVERS->value => [
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::JOBS->value => [
                'dispatched_from' => ['file', 'line', 'method'],
                'dispatches' => ['file', 'line', 'target'],
            ],
            Sections::MAILABLES->value => [
                'sent_from' => ['file', 'line', 'method'],
            ],
            Sections::NOTIFICATIONS->value => [
                'notified_from' => ['file', 'line', 'method'],
            ],
        ];
    }
}
