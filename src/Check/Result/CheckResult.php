<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Result;

/**
 * The aggregate result of a `loom:check` run: one {@see RuleReport} per rule in
 * registry order. The command's exit code derives from {@see passed()}.
 *
 * @internal
 */
final readonly class CheckResult
{
    /**
     * @param  list<RuleReport>  $reports
     */
    public function __construct(
        public array $reports,
    ) {}

    public function passed(): bool
    {
        foreach ($this->reports as $report) {
            if (! $report->passed()) {
                return false;
            }
        }

        return true;
    }

    public function violationCount(): int
    {
        $count = 0;
        foreach ($this->reports as $report) {
            $count += count($report->violations);
        }

        return $count;
    }
}
