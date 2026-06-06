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
 * Flags listeners that handle no events: dead registrations that can never fire.
 */
final class OrphanListenersRule implements CheckRule
{
    public function key(): RuleKey
    {
        return RuleKey::ORPHAN_LISTENERS;
    }

    public function description(): string
    {
        return 'Every listener handles at least one event';
    }

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        foreach ($context->section(Sections::LISTENERS) as $listener) {
            $handles = $listener[Field::HANDLES->value] ?? null;
            if (is_array($handles) && $handles !== []) {
                continue;
            }

            $fqcn = $this->string($listener[Field::FQCN->value] ?? null);
            $violations[] = new Violation(
                "Listener {$fqcn} handles no events.",
                [
                    'fqcn' => $listener[Field::FQCN->value] ?? null,
                    'file' => $listener[Field::FILE->value] ?? null,
                    'line' => $listener[Field::LINE->value] ?? null,
                ],
            );
        }

        usort($violations, static fn (Violation $a, Violation $b): int => $a->message <=> $b->message);

        return $violations;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
