<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\CrossLink;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Support\Sorting;

/**
 * Final phase: puts every section, and every nested array that is a set
 * rather than an ordered list, into a total deterministic order so two scans
 * of identical source emit byte-identical JSON regardless of filesystem order.
 *
 * Ordered lists whose source order is meaningful (`middleware`, `channels`,
 * `arguments`) are left as written.
 *
 * @internal
 */
final class SortPhase implements CrossLinkPhase
{
    public function apply(CrossLinkContext $context): void
    {
        foreach ($this->sectionKeys() as $section => $keys) {
            $entries = array_values($context->sections[$section] ?? []);
            usort($entries, Sorting::byKeys($keys));
            $context->sections[$section] = $entries;
        }

        foreach ($this->nestedKeys() as $section => $fields) {
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
     * Section value => sort keys. Each tuple is the entry's natural key
     * followed by tie-breakers, so the order is total.
     *
     * @return array<string, list<string>>
     */
    private function sectionKeys(): array
    {
        $file = Field::FILE->value;
        $line = Field::LINE->value;

        return [
            Sections::EVENTS->value => [Field::ID->value],
            Sections::MODEL_EVENTS->value => [Field::ID->value],
            Sections::LISTENERS->value => [Field::FQCN->value],
            Sections::OBSERVERS->value => [Field::FQCN->value, Field::OBSERVES->value],
            Sections::JOBS->value => [Field::FQCN->value],
            Sections::MAILABLES->value => [Field::FQCN->value],
            Sections::NOTIFICATIONS->value => [Field::FQCN->value],
            Sections::CLOSURE_LISTENERS->value => [
                Field::EVENT->value, $file, $line, Field::END_LINE->value, Field::REGISTRATION->value,
            ],
            Sections::SCHEDULED->value => [
                $file, $line, Field::KIND->value, Field::TARGET->value, Field::NAME->value, Field::CRON->value,
            ],
            Sections::ROUTES->value => [
                $file, $line, Field::METHOD->value, Field::URI->value, Field::NAME->value,
            ],
            Sections::UNRESOLVED_DISPATCHES->value => [
                $file, $line, Field::EXPRESSION->value, Field::REASON->value,
            ],
        ];
    }

    /**
     * Section value => field => sort keys for nested sets.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function nestedKeys(): array
    {
        $file = Field::FILE->value;
        $line = Field::LINE->value;
        $site = [$file, $line, Field::METHOD->value, Field::MODE->value];
        $dispatch = [$file, $line, Field::TARGET->value, Field::KIND->value];

        return [
            Sections::EVENTS->value => [
                Field::HANDLED_BY->value => [Field::LISTENER->value, Field::METHOD->value],
                Field::DISPATCHED_FROM->value => $site,
            ],
            Sections::MODEL_EVENTS->value => [
                Field::HANDLED_BY->value => [Field::HANDLER->value, Field::METHOD->value, $file, $line],
            ],
            Sections::LISTENERS->value => [
                Field::HANDLES->value => [Field::EVENT->value, Field::METHOD->value],
                Field::DISPATCHES->value => $dispatch,
            ],
            Sections::CLOSURE_LISTENERS->value => [
                Field::DISPATCHES->value => $dispatch,
            ],
            Sections::OBSERVERS->value => [
                Field::DISPATCHES->value => $dispatch,
            ],
            Sections::JOBS->value => [
                Field::DISPATCHED_FROM->value => $site,
                Field::DISPATCHES->value => $dispatch,
            ],
            Sections::MAILABLES->value => [
                Field::SENT_FROM->value => $site,
            ],
            Sections::NOTIFICATIONS->value => [
                Field::NOTIFIED_FROM->value => $site,
            ],
            Sections::ROUTES->value => [
                Field::DISPATCHES->value => $dispatch,
            ],
        ];
    }
}
