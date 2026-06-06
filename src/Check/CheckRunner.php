<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check;

use Lucasp\Loom\Check\Result\CheckResult;
use Lucasp\Loom\Check\Result\RuleReport;

/**
 * Runs every registered rule against a context, honoring a skip list, and
 * collects the per-rule reports into a {@see CheckResult}.
 */
final class CheckRunner
{
    public function __construct(
        private readonly CheckRuleRegistry $registry = new CheckRuleRegistry,
    ) {
    }

    /**
     * @param  list<string>  $skip  RuleKey values to skip
     */
    public function run(CheckContext $context, array $skip = []): CheckResult
    {
        $reports = [];
        foreach ($this->registry->rules() as $rule) {
            $skipped = in_array($rule->key()->value, $skip, true);
            $violations = $skipped ? [] : $rule->check($context);
            $reports[] = new RuleReport($rule->key(), $rule->description(), $skipped, $violations);
        }

        return new CheckResult($reports);
    }
}
