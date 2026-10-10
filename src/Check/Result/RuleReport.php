<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Result;

use Lucasp\Loom\Check\RuleKey;

/**
 * The outcome of one rule against one index: the rule that ran, whether it was
 * skipped, and any violations it produced.
 *
 * @internal
 */
final readonly class RuleReport
{
    /**
     * @param  list<Violation>  $violations
     */
    public function __construct(
        public RuleKey $key,
        public string $description,
        public bool $skipped,
        public array $violations,
    ) {}

    public function passed(): bool
    {
        return $this->skipped || $this->violations === [];
    }
}
