<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

use Lucasp\Loom\Check\Result\CheckResult;

/**
 * Human-readable terminal report with ANSI colors: a bold heading per failing
 * rule, red crosses for each violation, and a closing summary line. Passing and
 * skipped rules are omitted to keep the output focused on what to fix.
 */
final class TextCheckFormatter implements CheckFormatter
{
    private const GREEN = "\033[32m";

    private const RED = "\033[31m";

    private const YELLOW = "\033[33m";

    private const BOLD = "\033[1m";

    private const RESET = "\033[0m";

    public function format(CheckResult $result): string
    {
        if ($result->passed()) {
            return self::GREEN.'All checks passed.'.self::RESET;
        }

        $lines = [];
        $failingRules = 0;
        foreach ($result->reports as $report) {
            if ($report->violations === []) {
                continue;
            }
            $failingRules++;
            $lines[] = self::BOLD.$report->key->value.' — '.$report->description.self::RESET;
            foreach ($report->violations as $violation) {
                $lines[] = self::RED.'  ✗ '.$violation->message.self::RESET;
            }
        }

        $lines[] = self::YELLOW.sprintf(
            '%d violation(s) across %d rule(s).',
            $result->violationCount(),
            $failingRules,
        ).self::RESET;

        return implode("\n", $lines);
    }
}
