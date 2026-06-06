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
 * Gates unresolved dispatches. In strict mode any unresolved dispatch fails;
 * otherwise, with a baseline, only dispatches absent from the baseline (newly
 * introduced) fail. Without a baseline and without strict mode, growth cannot
 * be determined, so nothing fails.
 */
final class UnresolvedDispatchesRule implements CheckRule
{
    private const SEP = "\x1F";

    public function key(): RuleKey
    {
        return RuleKey::UNRESOLVED_DISPATCHES;
    }

    public function description(): string
    {
        return 'No new unresolved dispatches (strict: none at all)';
    }

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array
    {
        $entries = $context->section(Sections::UNRESOLVED_DISPATCHES);

        if ($context->strict()) {
            $offending = $entries;
        } elseif ($context->hasBaseline()) {
            $baseline = $this->identities($context->baselineSection(Sections::UNRESOLVED_DISPATCHES));
            $offending = [];
            foreach ($entries as $entry) {
                if (! isset($baseline[$this->identity($entry)])) {
                    $offending[] = $entry;
                }
            }
        } else {
            return [];
        }

        $violations = [];
        foreach ($offending as $entry) {
            $reason = $this->string($entry[Field::REASON->value] ?? null);
            $file = $this->string($entry[Field::FILE->value] ?? null);
            $line = $entry[Field::LINE->value] ?? null;
            $expression = $this->string($entry[Field::EXPRESSION->value] ?? null);

            $violations[] = new Violation(
                "Unresolved dispatch ({$reason}) at {$file}:".$this->string($line).": {$expression}",
                [
                    'reason' => $entry[Field::REASON->value] ?? null,
                    'file' => $entry[Field::FILE->value] ?? null,
                    'line' => $entry[Field::LINE->value] ?? null,
                    'expression' => $entry[Field::EXPRESSION->value] ?? null,
                ],
            );
        }

        usort(
            $violations,
            static fn (Violation $a, Violation $b): int => $a->message <=> $b->message,
        );

        return $violations;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,true>
     */
    private function identities(array $entries): array
    {
        $set = [];
        foreach ($entries as $entry) {
            $set[$this->identity($entry)] = true;
        }

        return $set;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function identity(array $entry): string
    {
        return implode(self::SEP, [
            $this->string($entry[Field::FILE->value] ?? null),
            $this->string($entry[Field::LINE->value] ?? null),
            $this->string($entry[Field::EXPRESSION->value] ?? null),
        ]);
    }

    private function string(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
