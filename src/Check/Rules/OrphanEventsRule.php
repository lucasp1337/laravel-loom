<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Rules;

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\Contracts\CheckRule;
use Lucasp\Loom\Check\Result\Violation;
use Lucasp\Loom\Check\RuleKey;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Sections;

/**
 * Flags events that are neither dispatched nor handled: dead definitions with
 * no producer and no consumer.
 */
final class OrphanEventsRule implements CheckRule
{
    public function key(): RuleKey
    {
        return RuleKey::ORPHAN_EVENTS;
    }

    public function description(): string
    {
        return 'Every event is dispatched or handled';
    }

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        foreach ($context->section(Sections::EVENTS) as $event) {
            if ($this->present($event, Field::HANDLED_BY) || $this->present($event, Field::DISPATCHED_FROM)) {
                continue;
            }

            $fqcn = $this->string($event[Field::FQCN->value] ?? null);
            $violations[] = new Violation(
                "Event {$fqcn} is never dispatched and never handled.",
                ['fqcn' => $event[Field::FQCN->value] ?? null],
            );
        }

        usort($violations, static fn (Violation $a, Violation $b): int => $a->message <=> $b->message);

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function present(array $entry, Field $field): bool
    {
        $value = $entry[$field->value] ?? null;

        return is_array($value) && $value !== [];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
