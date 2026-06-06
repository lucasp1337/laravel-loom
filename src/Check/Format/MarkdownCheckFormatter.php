<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

use Lucasp\Loom\Check\Result\CheckResult;

/**
 * Markdown report suitable for PR comments: a section per failing rule with a
 * bullet per violation. A passing run renders a single line.
 */
final class MarkdownCheckFormatter implements CheckFormatter
{
    public function format(CheckResult $result): string
    {
        if ($result->passed()) {
            return 'All checks passed.';
        }

        $lines = ['## loom:check', ''];
        foreach ($result->reports as $report) {
            if ($report->violations === []) {
                continue;
            }
            $lines[] = '### '.$report->key->value.' — '.$report->description;
            foreach ($report->violations as $violation) {
                $lines[] = '- '.$violation->message;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines), "\n");
    }
}
