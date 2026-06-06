<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Contracts;

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\Result\Violation;
use Lucasp\Loom\Check\RuleKey;

/**
 * A single policy rule applied to a decoded Loom index. Rules are pure: they
 * read the {@see CheckContext} and return violations without side effects.
 */
interface CheckRule
{
    public function key(): RuleKey;

    public function description(): string;

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array;
}
