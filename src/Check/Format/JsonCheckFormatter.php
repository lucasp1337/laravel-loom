<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

use Lucasp\Loom\Check\Result\CheckResult;
use Lucasp\Loom\Check\Result\RuleReport;
use Lucasp\Loom\Check\Result\Violation;

/**
 * Machine-readable report as pretty JSON. Every rule is included (even passing
 * or skipped) so consumers can see the full set that ran.
 */
final class JsonCheckFormatter implements CheckFormatter
{
    public function format(CheckResult $result): string
    {
        $payload = [
            'passed' => $result->passed(),
            'violation_count' => $result->violationCount(),
            'rules' => array_map(
                fn (RuleReport $report): array => $this->rule($report),
                $result->reports,
            ),
        ];

        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function rule(RuleReport $report): array
    {
        return [
            'key' => $report->key->value,
            'description' => $report->description,
            'skipped' => $report->skipped,
            'violations' => array_map(
                static fn (Violation $violation): array => [
                    'message' => $violation->message,
                    'context' => $violation->context,
                ],
                $report->violations,
            ),
        ];
    }
}
