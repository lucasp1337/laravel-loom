<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Rules;

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\Contracts\CheckRule;
use Lucasp\Loom\Check\DispatchGraph;
use Lucasp\Loom\Check\Result\Violation;
use Lucasp\Loom\Check\RuleKey;

/**
 * Flags cyclic dispatch chains across events and jobs, where dispatching one
 * primitive can transitively re-trigger itself.
 */
final class CyclicDispatchRule implements CheckRule
{
    private const ARROW = " \u{2192} ";

    public function key(): RuleKey
    {
        return RuleKey::CYCLIC_DISPATCH;
    }

    public function description(): string
    {
        return 'No cyclic event/job dispatch chains';
    }

    /**
     * @return list<Violation>
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        foreach (DispatchGraph::fromContext($context)->cycles() as $cycle) {
            $violations[] = new Violation(
                'Cyclic dispatch: '.implode(self::ARROW, $cycle),
                ['cycle' => $cycle],
            );
        }

        return $violations;
    }
}
